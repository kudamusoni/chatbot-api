<?php

namespace Tests\Feature\Http\App;

use App\Enums\CatalogMappingField;
use App\Models\CatalogImport;
use App\Models\Client;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CatalogImportContractsTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_before_validate_returns_invalid_import_state(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $user = User::factory()->create();
        $user->clients()->attach($client->id, ['role' => 'admin']);

        $import = CatalogImport::create([
            'client_id' => $client->id,
            'created_by' => $user->id,
            'status' => 'UPLOADED',
            'attempt' => 1,
        ]);

        $this->actingAs($user, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->postJson("/app/catalog-imports/{$import->id}/start", [
                'mapping' => [
                    'title' => 'title',
                    'price' => 'price',
                    'currency' => 'currency',
                    'source' => 'source',
                ],
            ])
            ->assertStatus(409)
            ->assertJson([
                'error' => 'CONFLICT',
                'reason_code' => 'INVALID_IMPORT_STATE',
            ]);
    }

    public function test_start_with_unknown_mapping_key_returns_422(): void
    {
        config()->set('catalog.import_disk', 'local');
        Storage::fake('local');

        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $user = User::factory()->create();
        $user->clients()->attach($client->id, ['role' => 'admin']);
        Storage::disk('local')->put('imports/unknown-key.csv', "title,price,currency,source\nRolex,100,GBP,sold\n");

        $import = CatalogImport::create([
            'client_id' => $client->id,
            'created_by' => $user->id,
            'status' => 'VALIDATED',
            'attempt' => 1,
            'file_path' => 'imports/unknown-key.csv',
            'validated_header' => ['title', 'price', 'currency', 'source'],
        ]);

        $this->actingAs($user, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->postJson("/app/catalog-imports/{$import->id}/start", [
                'mapping' => [
                    'title' => 'title',
                    'price' => 'price',
                    'currency' => 'currency',
                    'source' => 'source',
                    'maker' => 'title',
                ],
            ])
            ->assertStatus(422);
    }

    public function test_start_with_non_canonical_mapping_key_casing_returns_422(): void
    {
        config()->set('catalog.import_disk', 'local');
        Storage::fake('local');

        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $user = User::factory()->create();
        $user->clients()->attach($client->id, ['role' => 'admin']);
        Storage::disk('local')->put('imports/non-canonical.csv', "title,price,currency,source\nRolex,100,GBP,sold\n");

        $import = CatalogImport::create([
            'client_id' => $client->id,
            'created_by' => $user->id,
            'status' => 'VALIDATED',
            'attempt' => 1,
            'file_path' => 'imports/non-canonical.csv',
            'validated_header' => ['title', 'price', 'currency', 'source'],
        ]);

        $this->actingAs($user, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->postJson("/app/catalog-imports/{$import->id}/start", [
                'mapping' => [
                    'Title' => 'title',
                    'price' => 'price',
                    'currency' => 'currency',
                    'source' => 'source',
                ],
            ])
            ->assertStatus(422);
    }

    public function test_retry_returns_conflict_when_import_is_running(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $user = User::factory()->create();
        $user->clients()->attach($client->id, ['role' => 'admin']);

        $import = CatalogImport::create([
            'client_id' => $client->id,
            'created_by' => $user->id,
            'status' => 'RUNNING',
            'attempt' => 1,
        ]);

        $this->actingAs($user, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->postJson("/app/catalog-imports/{$import->id}/retry")
            ->assertStatus(409)
            ->assertJson([
                'error' => 'CONFLICT',
                'reason_code' => 'IMPORT_RUNNING',
            ]);
    }

    public function test_retry_returns_invalid_state_when_import_is_failed(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $user = User::factory()->create();
        $user->clients()->attach($client->id, ['role' => 'admin']);

        $import = CatalogImport::create([
            'client_id' => $client->id,
            'created_by' => $user->id,
            'status' => 'FAILED',
            'attempt' => 1,
        ]);

        $this->actingAs($user, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->postJson("/app/catalog-imports/{$import->id}/retry")
            ->assertStatus(409)
            ->assertJson([
                'error' => 'CONFLICT',
                'reason_code' => 'INVALID_IMPORT_STATE',
            ]);
    }

    public function test_errors_download_returns_204_when_no_errors(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $user = User::factory()->create();
        $user->clients()->attach($client->id, ['role' => 'admin']);

        $import = CatalogImport::create([
            'client_id' => $client->id,
            'created_by' => $user->id,
            'status' => 'FAILED',
            'attempt' => 1,
            'errors_count' => 0,
        ]);

        $this->actingAs($user, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->get("/app/catalog-imports/{$import->id}/errors")
            ->assertStatus(204);
    }

    public function test_errors_download_streams_csv_when_errors_exist(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $user = User::factory()->create();
        $user->clients()->attach($client->id, ['role' => 'admin']);

        $import = CatalogImport::create([
            'client_id' => $client->id,
            'created_by' => $user->id,
            'status' => 'FAILED',
            'attempt' => 1,
            'errors_count' => 1,
        ]);

        DB::table('catalog_import_errors')->insert([
            [
                'import_id' => $import->id,
                'row_number' => 3,
                'column' => 'title',
                'message' => 'Missing title',
                'raw' => json_encode(['title' => '']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'import_id' => $import->id,
                'row_number' => 2,
                'column' => 'price',
                'message' => 'Invalid price',
                'raw' => json_encode(['price' => 'abc']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'import_id' => $import->id,
                'row_number' => 3,
                'column' => 'currency',
                'message' => 'Invalid currency',
                'raw' => json_encode(['currency' => 'X']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->actingAs($user, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->get("/app/catalog-imports/{$import->id}/errors");

        $response->assertOk();
        $contentType = strtolower((string) $response->headers->get('content-type'));
        $this->assertStringContainsString('text/csv', $contentType);
        $this->assertStringContainsString('charset=utf-8', $contentType);
        $lines = preg_split("/\r\n|\n|\r/", trim((string) $response->getContent())) ?: [];
        $rows = array_map(static fn (string $line): array => str_getcsv($line), $lines);

        $this->assertSame(['row_number', 'column', 'message'], $rows[0] ?? []);
        $this->assertSame(['2', 'price', 'Invalid price'], $rows[1] ?? []);
        $this->assertSame(['3', 'title', 'Missing title'], $rows[2] ?? []);
        $this->assertSame(['3', 'currency', 'Invalid currency'], $rows[3] ?? []);
    }

    public function test_catalog_mapping_enum_exposes_canonical_api_keys(): void
    {
        $this->assertSame('title', CatalogMappingField::TITLE->key());
        $this->assertSame(
            ['title', 'price', 'currency', 'source', 'description', 'sold_at', 'low_estimate', 'high_estimate'],
            CatalogMappingField::allKeys()
        );
        $this->assertSame(
            ['title'],
            CatalogMappingField::requiredKeys()
        );
        $this->assertSame(
            ['price', 'currency', 'source', 'description', 'sold_at', 'low_estimate', 'high_estimate'],
            CatalogMappingField::optionalKeys()
        );
    }

    public function test_start_allows_low_and_high_estimate_mapping_without_price_mapping(): void
    {
        config()->set('catalog.import_disk', 'local');
        Storage::fake('local');

        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $user = User::factory()->create();
        $user->clients()->attach($client->id, ['role' => 'admin']);
        Storage::disk('local')->put(
            'imports/range-only.csv',
            "title,low_estimate,high_estimate,currency,source\nWatch,100,200,GBP,estimate\n"
        );

        $import = CatalogImport::create([
            'client_id' => $client->id,
            'created_by' => $user->id,
            'status' => 'VALIDATED',
            'attempt' => 1,
            'file_path' => 'imports/range-only.csv',
            'validated_header' => ['title', 'low_estimate', 'high_estimate', 'currency', 'source'],
        ]);

        $this->actingAs($user, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->postJson("/app/catalog-imports/{$import->id}/start", [
                'mapping' => [
                    'title' => 'title',
                    'low_estimate' => 'low_estimate',
                    'high_estimate' => 'high_estimate',
                    'currency' => 'currency',
                    'source' => 'source',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'QUEUED');
    }

    public function test_validate_denies_cross_tenant_import_binding(): void
    {
        $clientA = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $clientB = Client::create(['name' => 'Client B', 'slug' => 'client-b', 'settings' => []]);
        $user = User::factory()->create();
        $user->clients()->attach($clientA->id, ['role' => 'admin']);

        $import = CatalogImport::create([
            'client_id' => $clientB->id,
            'created_by' => $user->id,
            'status' => 'CREATED',
            'attempt' => 1,
        ]);

        $this->actingAs($user, 'web')
            ->withSession(['active_client_id' => $clientA->id])
            ->postJson("/app/catalog-imports/{$import->id}/validate")
            ->assertStatus(403)
            ->assertJson([
                'error' => 'FORBIDDEN',
                'reason_code' => 'NOT_A_CLIENT_MEMBER',
            ]);
    }

    public function test_validate_missing_file_returns_import_file_missing(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $user = User::factory()->create();
        $user->clients()->attach($client->id, ['role' => 'admin']);

        $import = CatalogImport::create([
            'client_id' => $client->id,
            'created_by' => $user->id,
            'status' => 'UPLOADED',
            'attempt' => 1,
            'file_path' => 'missing/file.csv',
        ]);

        $this->actingAs($user, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->postJson("/app/catalog-imports/{$import->id}/validate")
            ->assertStatus(409)
            ->assertJson([
                'error' => 'CONFLICT',
                'reason_code' => 'IMPORT_FILE_MISSING',
            ]);
    }

    public function test_show_returns_resume_ready_payload_with_mapping_and_preview(): void
    {
        config()->set('catalog.import_disk', 'local');
        config()->set('catalog.sample_rows', 25);
        Storage::fake('local');

        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $user = User::factory()->create();
        $user->clients()->attach($client->id, ['role' => 'admin']);

        Storage::disk('local')->put(
            'catalog-imports/' . $client->id . '/demo.csv',
            "title,price,currency,source\nRolex,1000,GBP,sold\nOmega,900,GBP,asking\n"
        );

        $import = CatalogImport::create([
            'client_id' => $client->id,
            'created_by' => $user->id,
            'status' => 'COMPLETED',
            'attempt' => 2,
            'file_path' => 'catalog-imports/' . $client->id . '/demo.csv',
            'mapping' => [
                'title' => 'title',
                'price' => 'price',
                'currency' => 'currency',
                'source' => 'source',
            ],
            'validated_header' => ['title', 'price', 'currency', 'source'],
            'totals' => [
                'processed' => 2,
                'inserted' => 1,
                'updated' => 1,
                'invalid' => 0,
            ],
            'errors_count' => 0,
            'finished_at' => Carbon::now('UTC'),
        ]);

        $response = $this->actingAs($user, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->getJson("/app/catalog-imports/{$import->id}")
            ->assertOk();

        $response->assertJsonPath('import.id', $import->id);
        $response->assertJsonPath('import.status', 'COMPLETED');
        $response->assertJsonPath('import.next_action', 'DONE');
        $response->assertJsonPath('import.can_start', false);
        $response->assertJsonPath('import.file_name', 'demo.csv');
        $response->assertJsonPath('import.columns.0', 'title');
        $response->assertJsonPath('import.sample_rows.0.0', 'Rolex');
        $response->assertJsonPath('import.preview_rows.0.title', 'Rolex');
        $response->assertJsonPath('import.preview_rows.0.price', '1000');
        $response->assertJsonPath('import.totals.rows_total', 2);
        $response->assertJsonPath('import.totals.rows_imported', 2);
        $response->assertJsonPath('limits.max_rows', (int) config('catalog.max_rows'));
        $response->assertJsonPath('limits.max_bytes', (int) config('catalog.max_bytes'));
    }
}
