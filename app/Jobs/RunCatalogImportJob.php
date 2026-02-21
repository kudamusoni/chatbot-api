<?php

namespace App\Jobs;

use App\Enums\CatalogImportStatus;
use App\Enums\CatalogMappingField;
use App\Enums\ProductSource;
use App\Models\CatalogImport;
use App\Services\PriceParser;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class RunCatalogImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const EPOCH = '1970-01-01 00:00:00';

    public function __construct(
        public readonly string $importId,
        public readonly int $attemptNumber
    ) {}

    public function handle(PriceParser $priceParser): void
    {
        $import = null;
        $canRun = DB::transaction(function () use (&$import) {
            $import = CatalogImport::query()->where('id', $this->importId)->lockForUpdate()->first();

            if (!$import) {
                return false;
            }

            if ($import->status !== CatalogImportStatus::QUEUED || (int) $import->attempt !== $this->attemptNumber) {
                return false;
            }

            $import->update([
                'status' => CatalogImportStatus::RUNNING,
                'started_at' => now(),
                'finished_at' => null,
            ]);

            return true;
        });

        if (!$canRun || !$import) {
            return;
        }

        $disk = (string) config('catalog.import_disk', 'local');
        if (!$import->file_path || !Storage::disk($disk)->exists($import->file_path) || !is_array($import->mapping)) {
            $this->markFailed($import, ['message' => 'Import file or mapping missing']);

            return;
        }

        $stream = Storage::disk($disk)->readStream($import->file_path);
        if (!$stream) {
            $this->markFailed($import, ['message' => 'Unable to open import stream']);

            return;
        }

        try {
            $header = fgetcsv($stream) ?: [];
            $header = array_map(fn ($v) => (string) $v, $header);

            $mapping = $import->mapping;
            foreach (CatalogMappingField::requiredKeys() as $key) {
                $mappedCol = $mapping[$key] ?? null;
                if (!is_string($mappedCol) || !in_array($mappedCol, $header, true)) {
                    $this->markFailed($import, ['message' => 'Mapped columns not found in header']);
                    fclose($stream);

                    return;
                }
            }

            $totals = [
                'processed_rows' => 0,
                'inserted' => 0,
                'updated' => 0,
                'invalid_rows' => 0,
            ];

            $errors = [];
            $headerIndex = array_flip($header);

            while (($row = fgetcsv($stream)) !== false) {
                $totals['processed_rows']++;
                $rowNum = $totals['processed_rows'] + 1;

                $record = $this->mapRow($mapping, $row, $headerIndex);
                $validation = $this->validateAndNormalize($record, $priceParser);

                if (!$validation['ok']) {
                    $totals['invalid_rows']++;
                    $errors[] = [
                        'import_id' => $import->id,
                        'row_number' => $rowNum,
                        'column' => $validation['column'],
                        'message' => $validation['message'],
                        'raw' => json_encode($record),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    continue;
                }

                $attrs = $validation['attrs'];

                $exists = DB::table('product_catalog')
                    ->where('client_id', $import->client_id)
                    ->where('source', $attrs['source'])
                    ->where('normalized_title_hash', $attrs['normalized_title_hash'])
                    ->where('price', $attrs['price'])
                    ->where('currency', $attrs['currency'])
                    ->where('sold_at_key', $attrs['sold_at_key'])
                    ->exists();

                DB::table('product_catalog')->upsert(
                    [array_merge($attrs, [
                        'id' => (string) \Illuminate\Support\Str::uuid(),
                        'client_id' => $import->client_id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])],
                    ['client_id', 'source', 'normalized_title_hash', 'price', 'currency', 'sold_at_key'],
                    ['title', 'description', 'sold_at', 'sold_at_key', 'updated_at']
                );

                if ($exists) {
                    $totals['updated']++;
                } else {
                    $totals['inserted']++;
                }
            }

            fclose($stream);

            DB::transaction(function () use ($import, $errors, $totals): void {
                DB::table('catalog_import_errors')->where('import_id', $import->id)->delete();
                if ($errors !== []) {
                    DB::table('catalog_import_errors')->insert($errors);
                }

                $import->refresh();
                if ((int) $import->attempt !== $this->attemptNumber) {
                    return;
                }

                $import->update([
                    'status' => CatalogImportStatus::COMPLETED,
                    'totals' => $totals,
                    'errors_count' => count($errors),
                    'errors_sample' => array_slice(array_map(fn ($e) => [
                        'row_number' => $e['row_number'],
                        'column' => $e['column'],
                        'message' => $e['message'],
                    ], $errors), 0, 10),
                    'finished_at' => now(),
                ]);
            });
        } catch (\Throwable $e) {
            if (is_resource($stream)) {
                fclose($stream);
            }

            $this->markFailed($import, ['message' => $e->getMessage()]);
            throw $e;
        }
    }

    private function markFailed(CatalogImport $import, array $meta): void
    {
        Log::error('Catalog import failed', array_merge($meta, [
            'import_id' => $import->id,
            'attempt' => $this->attemptNumber,
        ]));

        $import->update([
            'status' => CatalogImportStatus::FAILED,
            'finished_at' => now(),
        ]);
    }

    /** @param array<string, string> $mapping @param array<int, string|null> $row @param array<string, int> $headerIndex
     *  @return array<string, string|null>
     */
    private function mapRow(array $mapping, array $row, array $headerIndex): array
    {
        $out = [];

        foreach (CatalogMappingField::allKeys() as $field) {
            $column = $mapping[$field] ?? null;
            if (!is_string($column) || !array_key_exists($column, $headerIndex)) {
                $out[$field] = null;
                continue;
            }

            $out[$field] = isset($row[$headerIndex[$column]]) ? trim((string) $row[$headerIndex[$column]]) : null;
        }

        return $out;
    }

    /** @param array<string, string|null> $record
     *  @return array{ok:bool, column?:string, message?:string, attrs?:array<string, mixed>}
     */
    private function validateAndNormalize(array $record, PriceParser $priceParser): array
    {
        $title = trim((string) ($record['title'] ?? ''));
        if ($title === '') {
            return ['ok' => false, 'column' => 'title', 'message' => 'Missing title'];
        }

        $priceMinor = $priceParser->parseToMinorUnits((string) ($record['price'] ?? ''));
        if ($priceMinor === null) {
            return ['ok' => false, 'column' => 'price', 'message' => 'Invalid price'];
        }

        $currency = strtoupper(trim((string) ($record['currency'] ?? '')));
        if ($currency === '' || strlen($currency) !== 3) {
            return ['ok' => false, 'column' => 'currency', 'message' => 'Invalid currency'];
        }

        $source = strtolower(trim((string) ($record['source'] ?? '')));
        if (!in_array($source, array_map(fn ($c) => $c->value, ProductSource::cases()), true)) {
            return ['ok' => false, 'column' => 'source', 'message' => 'Invalid source'];
        }

        $soldAt = null;
        if (!empty($record['sold_at'])) {
            try {
                $soldAt = Carbon::parse((string) $record['sold_at'])->toDateTimeString();
            } catch (\Throwable) {
                return ['ok' => false, 'column' => 'sold_at', 'message' => 'Invalid sold_at'];
            }
        }

        $description = isset($record['description']) ? trim((string) $record['description']) : null;
        $normalizedText = strtolower(trim(preg_replace('/\s+/', ' ', trim($title . ' ' . ($description ?? ''))) ?? ''));
        $normalizedTitleHash = hash('sha256', strtolower(trim(preg_replace('/\s+/', ' ', $title) ?? '')));

        return [
            'ok' => true,
            'attrs' => [
                'title' => $title,
                'description' => $description !== '' ? $description : null,
                'source' => $source,
                'price' => $priceMinor,
                'currency' => $currency,
                'sold_at' => $soldAt,
                'sold_at_key' => $soldAt ?? self::EPOCH,
                'normalized_text' => $normalizedText,
                'normalized_title_hash' => $normalizedTitleHash,
            ],
        ];
    }
}
