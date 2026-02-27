<?php

namespace App\Http\Controllers\App;

use App\Enums\AppDenyReason;
use App\Enums\CatalogMappingField;
use App\Enums\CatalogImportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\StartCatalogImportRequest;
use App\Jobs\RunCatalogImportJob;
use App\Models\CatalogImport;
use App\Services\AuditLogger;
use App\Support\CurrentClient;
use App\Support\DashboardRange;
use App\Support\DashboardListDefaults;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class CatalogImportController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    private function denyMembership(): JsonResponse
    {
        return response()->json([
            'error' => 'FORBIDDEN',
            'reason_code' => AppDenyReason::NOT_A_CLIENT_MEMBER->value,
        ], 403);
    }

    private function findImportForCurrentClient(string $catalogImportId, CurrentClient $currentClient): ?CatalogImport
    {
        return CatalogImport::query()
            ->where('client_id', $currentClient->id())
            ->where('id', $catalogImportId)
            ->first();
    }

    public function index(Request $request): JsonResponse
    {
        /** @var CurrentClient $currentClient */
        $currentClient = app(CurrentClient::class);
        $perPage = DashboardListDefaults::perPage($request);

        $importsQuery = CatalogImport::query()
            ->where('client_id', $currentClient->id());

        if ($request->filled('status')) {
            $status = strtoupper(trim((string) $request->query('status')));
            $allowedStatuses = array_map(
                static fn (CatalogImportStatus $value): string => $value->value,
                CatalogImportStatus::cases()
            );

            if (!in_array($status, $allowedStatuses, true)) {
                throw ValidationException::withMessages([
                    'status' => ['The selected status is invalid.'],
                ]);
            }

            $importsQuery->where('status', $status);
        }

        if ($request->filled('range')) {
            try {
                $range = DashboardRange::parse((string) $request->query('range'));
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages([
                    'range' => ['The selected range is invalid.'],
                ]);
            }

            if ($range->from !== null) {
                $importsQuery->whereBetween('created_at', [$range->from, $range->to]);
            }
        }

        // Unsupported sort params are intentionally ignored until explicitly supported.
        DashboardListDefaults::applyDefaultSort($importsQuery, 'catalog_imports');

        $imports = $importsQuery
            ->paginate($perPage);

        return response()->json(DashboardListDefaults::withDefaultSortMeta($imports, 'catalog_imports'));
    }

    public function store(Request $request): JsonResponse
    {
        /** @var CurrentClient $currentClient */
        $currentClient = app(CurrentClient::class);
        $user = $request->user();

        $draftStatuses = [
            CatalogImportStatus::CREATED->value,
            CatalogImportStatus::UPLOADED->value,
            CatalogImportStatus::VALIDATED->value,
        ];

        $existingDraft = CatalogImport::query()
            ->where('client_id', $currentClient->id())
            ->where('created_by', $user->id)
            ->whereIn('status', $draftStatuses)
            ->orderByDesc('created_at')
            ->first();

        $wasReused = $existingDraft !== null;

        $import = $existingDraft ?? CatalogImport::create([
            'client_id' => $currentClient->id(),
            'created_by' => $user->id,
            'status' => CatalogImportStatus::CREATED,
            'attempt' => 1,
            'mapping' => null,
            'validated_header' => null,
            'totals' => null,
            'errors_count' => 0,
            'errors_sample' => null,
            'queued_at' => null,
        ]);

        return response()->json([
            'ok' => true,
            'reused' => $wasReused,
            'import' => $import,
            'limits' => [
                'max_rows' => (int) config('catalog.max_rows'),
                'max_bytes' => (int) config('catalog.max_bytes'),
            ],
        ], $wasReused ? 200 : 201);
    }

    public function show(string $catalogImportId): JsonResponse
    {
        /** @var CurrentClient $currentClient */
        $currentClient = app(CurrentClient::class);
        $catalogImport = $this->findImportForCurrentClient($catalogImportId, $currentClient);

        if (!$catalogImport) {
            return $this->denyMembership();
        }

        return response()->json([
            'import' => $this->detailPayload($catalogImport),
            'limits' => [
                'max_rows' => (int) config('catalog.max_rows'),
                'max_bytes' => (int) config('catalog.max_bytes'),
            ],
        ]);
    }

    public function upload(Request $request, string $catalogImportId): JsonResponse
    {
        /** @var CurrentClient $currentClient */
        $currentClient = app(CurrentClient::class);
        /** @var \App\Models\User $actor */
        $actor = $request->user();
        $catalogImport = $this->findImportForCurrentClient($catalogImportId, $currentClient);

        if (!$catalogImport) {
            return $this->denyMembership();
        }

        $request->validate([
            'file' => [
                'required',
                'file',
                'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel',
                'max:' . (int) ceil(((int) config('catalog.max_bytes', 10485760)) / 1024),
            ],
        ]);

        $disk = (string) config('catalog.import_disk', 'local');
        $path = $request->file('file')->storeAs(
            'catalog-imports/' . $currentClient->id(),
            $catalogImport->id . '-attempt-' . (int) ($catalogImport->attempt ?? 1) . '.csv',
            $disk
        );

        $fileHash = hash_file('sha256', $request->file('file')->getRealPath());

        $catalogImport->update([
            'file_path' => $path,
            'file_hash' => $fileHash ?: null,
            'status' => CatalogImportStatus::UPLOADED,
            'validated_header' => null,
            'mapping' => null,
            'totals' => null,
            'errors_count' => 0,
            'errors_sample' => null,
            'queued_at' => null,
            'started_at' => null,
            'finished_at' => null,
        ]);

        $this->auditLogger->log($actor, 'catalog.import.uploaded', $currentClient->id(), [
            'import_id' => $catalogImport->id,
            'attempt' => (int) ($catalogImport->attempt ?? 1),
            'file_name' => basename($path),
            'ip' => $request->ip(),
            'ua' => (string) $request->userAgent(),
        ]);

        $validation = $this->runValidation($catalogImport);
        $catalogImport->refresh();

        return response()->json([
            'ok' => true,
            'id' => $catalogImport->id,
            'status' => $catalogImport->status,
            'attempt' => $catalogImport->attempt,
            'columns' => $validation['columns'],
            'sample_rows' => $validation['sample_rows'],
            'suggested_mapping' => (object) [],
            'errors' => [],
            'limits' => [
                'max_rows' => (int) config('catalog.max_rows'),
                'max_bytes' => (int) config('catalog.max_bytes'),
            ],
        ]);
    }

    public function validateImport(string $catalogImportId): JsonResponse
    {
        /** @var CurrentClient $currentClient */
        $currentClient = app(CurrentClient::class);
        $catalogImport = $this->findImportForCurrentClient($catalogImportId, $currentClient);

        if (!$catalogImport) {
            return $this->denyMembership();
        }

        $validated = $this->runValidation($catalogImport);

        return response()->json([
            'columns' => $validated['columns'],
            'sample_rows' => $validated['sample_rows'],
            'suggested_mapping' => (object) [],
            'errors' => [],
            'limits' => [
                'max_rows' => (int) config('catalog.max_rows'),
                'max_bytes' => (int) config('catalog.max_bytes'),
            ],
        ]);
    }

    public function start(Request $request, string $catalogImportId): JsonResponse
    {
        /** @var CurrentClient $currentClient */
        $currentClient = app(CurrentClient::class);
        $catalogImport = $this->findImportForCurrentClient($catalogImportId, $currentClient);

        if (!$catalogImport) {
            return $this->denyMembership();
        }

        $expectedStatuses = [CatalogImportStatus::VALIDATED->value];
        $currentStatus = $catalogImport->status instanceof CatalogImportStatus
            ? $catalogImport->status->value
            : (string) $catalogImport->status;

        if (!in_array($currentStatus, $expectedStatuses, true)) {
            Log::warning('Catalog import invalid state transition', [
                'reason_code' => AppDenyReason::INVALID_IMPORT_STATE->value,
                'current_status' => $currentStatus,
                'expected_statuses' => $expectedStatuses,
                'import_id' => $catalogImport->id,
                'client_id' => $currentClient->id(),
            ]);

            return response()->json([
                'error' => 'CONFLICT',
                'reason_code' => AppDenyReason::INVALID_IMPORT_STATE->value,
            ], 409);
        }

        if (!$catalogImport->file_path || !Storage::disk(config('catalog.import_disk'))->exists($catalogImport->file_path)) {
            return response()->json([
                'error' => 'CONFLICT',
                'reason_code' => AppDenyReason::IMPORT_FILE_MISSING->value,
            ], 409);
        }

        $validator = Validator::make($request->all(), StartCatalogImportRequest::mappingRules());

        if ($validator->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        /** @var array<string, string> $mapping */
        $mapping = $validator->validated()['mapping'];
        $validatedHeader = $catalogImport->validated_header ?? [];

        $columnErrors = [];
        foreach ($mapping as $field => $columnName) {
            if (!is_string($columnName) || !in_array($columnName, $validatedHeader, true)) {
                $columnErrors["mapping.{$field}"] = ["Column '{$columnName}' was not found in validated_header."];
            }
        }

        if ($columnErrors !== []) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $columnErrors,
            ], 422);
        }

        $queued = DB::transaction(function () use ($catalogImport, $mapping) {
            $locked = CatalogImport::query()->where('id', $catalogImport->id)->lockForUpdate()->first();
            if (!$locked) {
                return null;
            }

            $lockedStatus = $locked->status instanceof CatalogImportStatus
                ? $locked->status->value
                : (string) $locked->status;
            if ($lockedStatus !== CatalogImportStatus::VALIDATED->value) {
                return ['ok' => false, 'current_status' => $lockedStatus];
            }

            $locked->update([
                'mapping' => $mapping,
                'status' => CatalogImportStatus::QUEUED,
                'queued_at' => now(),
                'totals' => null,
                'errors_count' => 0,
                'errors_sample' => null,
                'started_at' => null,
                'finished_at' => null,
            ]);

            return ['ok' => true, 'model' => $locked];
        });

        if ($queued === null) {
            return response()->json([
                'error' => 'CONFLICT',
                'reason_code' => AppDenyReason::INVALID_IMPORT_STATE->value,
            ], 409);
        }

        if (($queued['ok'] ?? false) !== true) {
            Log::warning('Catalog import invalid state transition', [
                'reason_code' => AppDenyReason::INVALID_IMPORT_STATE->value,
                'current_status' => $queued['current_status'] ?? 'unknown',
                'expected_statuses' => $expectedStatuses,
                'import_id' => $catalogImport->id,
                'client_id' => $currentClient->id(),
            ]);

            return response()->json([
                'error' => 'CONFLICT',
                'reason_code' => AppDenyReason::INVALID_IMPORT_STATE->value,
            ], 409);
        }

        RunCatalogImportJob::dispatch((string) $catalogImport->id, (int) $catalogImport->attempt)
            ->onQueue('catalog-imports');

        return response()->json([
            'ok' => true,
            'id' => $catalogImport->id,
            'status' => CatalogImportStatus::QUEUED->value,
            'attempt' => $catalogImport->attempt,
        ]);
    }

    public function retry(string $catalogImportId): JsonResponse
    {
        /** @var CurrentClient $currentClient */
        $currentClient = app(CurrentClient::class);
        $catalogImport = $this->findImportForCurrentClient($catalogImportId, $currentClient);

        if (!$catalogImport) {
            return $this->denyMembership();
        }

        $status = $catalogImport->status instanceof CatalogImportStatus
            ? $catalogImport->status->value
            : (string) $catalogImport->status;

        if ($status === CatalogImportStatus::RUNNING->value) {
            return response()->json([
                'error' => 'CONFLICT',
                'reason_code' => AppDenyReason::IMPORT_RUNNING->value,
            ], 409);
        }

        DB::transaction(function () use ($catalogImport): void {
            DB::table('catalog_import_errors')
                ->where('import_id', $catalogImport->id)
                ->delete();

            $catalogImport->update([
                'attempt' => ((int) ($catalogImport->attempt ?? 1)) + 1,
                'status' => CatalogImportStatus::CREATED,
                'totals' => null,
                'errors_count' => 0,
                'errors_sample' => null,
                'queued_at' => null,
                'started_at' => null,
                'finished_at' => null,
            ]);
        });

        return response()->json([
            'ok' => true,
            'id' => $catalogImport->id,
            'attempt' => $catalogImport->fresh()->attempt,
            'status' => $catalogImport->fresh()->status,
        ]);
    }

    public function errors(string $catalogImportId): Response
    {
        /** @var CurrentClient $currentClient */
        $currentClient = app(CurrentClient::class);
        $catalogImport = $this->findImportForCurrentClient($catalogImportId, $currentClient);

        if (!$catalogImport) {
            return response()->json([
                'error' => 'FORBIDDEN',
                'reason_code' => AppDenyReason::NOT_A_CLIENT_MEMBER->value,
            ], 403);
        }

        $rows = DB::table('catalog_import_errors')
            ->where('import_id', $catalogImport->id)
            ->orderBy('row_number')
            ->orderBy('id')
            ->get(['row_number', 'column', 'message']);

        if ($rows->isEmpty()) {
            return response('', 204);
        }

        $csv = fopen('php://temp', 'r+');
        fputcsv($csv, ['row_number', 'column', 'message']);
        foreach ($rows as $row) {
            fputcsv($csv, [(string) $row->row_number, (string) $row->column, (string) $row->message]);
        }
        rewind($csv);
        $content = stream_get_contents($csv) ?: '';
        fclose($csv);

        return response($content, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="catalog-import-errors.csv"',
        ]);
    }

    /**
     * @return array{columns:array<int,string>,sample_rows:array<int,array<int,string|null>>}
     */
    private function runValidation(CatalogImport $catalogImport): array
    {
        if (!$catalogImport->file_path || !Storage::disk(config('catalog.import_disk'))->exists($catalogImport->file_path)) {
            throw new HttpResponseException(response()->json([
                'error' => 'CONFLICT',
                'reason_code' => AppDenyReason::IMPORT_FILE_MISSING->value,
            ], 409));
        }

        $stream = Storage::disk(config('catalog.import_disk'))->readStream($catalogImport->file_path);
        if (!$stream) {
            throw new HttpResponseException(response()->json([
                'error' => 'CONFLICT',
                'reason_code' => AppDenyReason::IMPORT_FILE_MISSING->value,
            ], 409));
        }

        $columns = fgetcsv($stream) ?: [];
        $columns = array_values(array_map(fn ($col) => (string) $col, $columns));
        $sampleRows = [];
        $sampleLimit = (int) config('catalog.sample_rows', 25);
        while (count($sampleRows) < $sampleLimit && ($row = fgetcsv($stream)) !== false) {
            $sampleRows[] = $row;
        }
        fclose($stream);

        $catalogImport->update([
            'validated_header' => $columns,
            'status' => CatalogImportStatus::VALIDATED,
        ]);

        return [
            'columns' => $columns,
            'sample_rows' => $sampleRows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detailPayload(CatalogImport $catalogImport): array
    {
        $status = $catalogImport->status instanceof CatalogImportStatus
            ? $catalogImport->status->value
            : (string) $catalogImport->status;

        $preview = $this->previewForImport($catalogImport);
        $mapping = is_array($catalogImport->mapping) ? $catalogImport->mapping : [];
        $totals = is_array($catalogImport->totals) ? $catalogImport->totals : [];
        $normalizedTotals = $this->normalizedTotals($totals);

        $errorsCount = (int) ($catalogImport->errors_count ?? 0);
        $canUpload = in_array($status, [
            CatalogImportStatus::CREATED->value,
            CatalogImportStatus::UPLOADED->value,
            CatalogImportStatus::VALIDATED->value,
        ], true);
        $canStart = $status === CatalogImportStatus::VALIDATED->value;
        $canRetry = $status === CatalogImportStatus::FAILED->value;
        $canDownloadErrors = $errorsCount > 0;

        return [
            'id' => $catalogImport->id,
            'status' => $status,
            'attempt' => (int) ($catalogImport->attempt ?? 1),
            'created_at' => $catalogImport->created_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'updated_at' => $catalogImport->updated_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'queued_at' => $catalogImport->queued_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'started_at' => $catalogImport->started_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'finished_at' => $catalogImport->finished_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'completed_at' => $status === CatalogImportStatus::COMPLETED->value
                ? $catalogImport->finished_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z')
                : null,
            'failed_at' => $status === CatalogImportStatus::FAILED->value
                ? $catalogImport->finished_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z')
                : null,
            'file_name' => $catalogImport->file_path ? basename($catalogImport->file_path) : null,
            'mapping' => $mapping,
            'columns' => $preview['columns'],
            'sample_rows' => $preview['sample_rows'],
            'preview_rows' => $this->mappedPreviewRows($mapping, $preview['columns'], $preview['sample_rows']),
            'totals' => array_merge($totals, $normalizedTotals),
            'errors_count' => $errorsCount,
            'errors_sample' => is_array($catalogImport->errors_sample) ? $catalogImport->errors_sample : [],
            'next_action' => $this->nextActionForStatus($status),
            'can_upload' => $canUpload,
            'can_start' => $canStart,
            'can_retry' => $canRetry,
            'can_download_errors' => $canDownloadErrors,
        ];
    }

    /**
     * @return array{columns:array<int,string>,sample_rows:array<int,array<int,string|null>>}
     */
    private function previewForImport(CatalogImport $catalogImport): array
    {
        $columns = is_array($catalogImport->validated_header) ? array_values($catalogImport->validated_header) : [];
        $sampleRows = [];

        $disk = (string) config('catalog.import_disk', 'local');
        if (!$catalogImport->file_path || !Storage::disk($disk)->exists($catalogImport->file_path)) {
            return ['columns' => $columns, 'sample_rows' => $sampleRows];
        }

        $stream = Storage::disk($disk)->readStream($catalogImport->file_path);
        if (!$stream) {
            return ['columns' => $columns, 'sample_rows' => $sampleRows];
        }

        $streamColumns = fgetcsv($stream) ?: [];
        $streamColumns = array_values(array_map(fn ($col) => (string) $col, $streamColumns));
        if ($streamColumns !== []) {
            $columns = $streamColumns;
        }

        $sampleLimit = (int) config('catalog.sample_rows', 25);
        while (count($sampleRows) < $sampleLimit && ($row = fgetcsv($stream)) !== false) {
            $sampleRows[] = $row;
        }
        fclose($stream);

        return [
            'columns' => $columns,
            'sample_rows' => $sampleRows,
        ];
    }

    /**
     * @param array<string, string> $mapping
     * @param array<int, string> $columns
     * @param array<int, array<int, string|null>> $sampleRows
     * @return array<int, array<string, string|null>>
     */
    private function mappedPreviewRows(array $mapping, array $columns, array $sampleRows): array
    {
        if ($mapping === [] || $columns === [] || $sampleRows === []) {
            return [];
        }

        $columnIndex = array_flip($columns);
        $rows = [];
        foreach ($sampleRows as $row) {
            $mapped = [];
            foreach ($mapping as $field => $columnName) {
                $index = $columnIndex[$columnName] ?? null;
                $mapped[$field] = $index !== null ? ($row[$index] ?? null) : null;
            }
            $rows[] = $mapped;
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $totals
     * @return array<string, int>
     */
    private function normalizedTotals(array $totals): array
    {
        $rowsTotal = (int) ($totals['processed'] ?? $totals['rows_total'] ?? 0);
        $rowsInvalid = (int) ($totals['invalid'] ?? $totals['rows_invalid'] ?? 0);
        $rowsInserted = (int) ($totals['inserted'] ?? 0);
        $rowsUpdated = (int) ($totals['updated'] ?? 0);
        $rowsImported = (int) ($totals['rows_imported'] ?? ($rowsInserted + $rowsUpdated));
        $rowsValid = (int) ($totals['rows_valid'] ?? max($rowsTotal - $rowsInvalid, 0));
        $rowsFailed = (int) ($totals['rows_failed'] ?? $rowsInvalid);

        return [
            'rows_total' => $rowsTotal,
            'rows_valid' => $rowsValid,
            'rows_invalid' => $rowsInvalid,
            'rows_imported' => $rowsImported,
            'rows_failed' => $rowsFailed,
        ];
    }

    private function nextActionForStatus(string $status): string
    {
        return match ($status) {
            CatalogImportStatus::CREATED->value => 'UPLOAD',
            CatalogImportStatus::UPLOADED->value => 'UPLOAD',
            CatalogImportStatus::VALIDATED->value => 'MAP_AND_START',
            CatalogImportStatus::QUEUED->value, CatalogImportStatus::RUNNING->value => 'WAITING',
            CatalogImportStatus::FAILED->value => 'RETRY',
            CatalogImportStatus::COMPLETED->value => 'DONE',
            default => 'WAITING',
        };
    }
}
