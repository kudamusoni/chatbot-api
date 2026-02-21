<?php

namespace App\Http\Controllers\App;

use App\Enums\AppDenyReason;
use App\Enums\CatalogMappingField;
use App\Enums\CatalogImportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\StartCatalogImportRequest;
use App\Jobs\RunCatalogImportJob;
use App\Models\CatalogImport;
use App\Support\CurrentClient;
use App\Support\DashboardListDefaults;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class CatalogImportController extends Controller
{
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

        $import = CatalogImport::create([
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
            'import' => $import,
            'limits' => [
                'max_rows' => (int) config('catalog.max_rows'),
                'max_bytes' => (int) config('catalog.max_bytes'),
            ],
        ], 201);
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
            'import' => $catalogImport,
        ]);
    }

    public function upload(Request $request, string $catalogImportId): JsonResponse
    {
        /** @var CurrentClient $currentClient */
        $currentClient = app(CurrentClient::class);
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

        return response()->json([
            'ok' => true,
            'id' => $catalogImport->id,
            'status' => $catalogImport->status,
            'attempt' => $catalogImport->attempt,
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

        if (!$catalogImport->file_path || !Storage::disk(config('catalog.import_disk'))->exists($catalogImport->file_path)) {
            return response()->json([
                'error' => 'CONFLICT',
                'reason_code' => AppDenyReason::IMPORT_FILE_MISSING->value,
            ], 409);
        }

        $stream = Storage::disk(config('catalog.import_disk'))->readStream($catalogImport->file_path);
        if (!$stream) {
            return response()->json([
                'error' => 'CONFLICT',
                'reason_code' => AppDenyReason::IMPORT_FILE_MISSING->value,
            ], 409);
        }

        $columns = fgetcsv($stream) ?: [];
        $columns = array_values(array_map(fn ($col) => (string) $col, $columns));
        $sampleRows = [];
        $errors = [];
        $sampleLimit = (int) config('catalog.sample_rows', 25);
        while (count($sampleRows) < $sampleLimit && ($row = fgetcsv($stream)) !== false) {
            $sampleRows[] = $row;
        }
        fclose($stream);

        $catalogImport->update([
            'validated_header' => $columns,
            'status' => CatalogImportStatus::VALIDATED,
        ]);

        return response()->json([
            'columns' => $columns,
            'sample_rows' => $sampleRows,
            'suggested_mapping' => (object) [],
            'errors' => $errors,
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
}
