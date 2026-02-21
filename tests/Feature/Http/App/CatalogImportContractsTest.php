<?php

namespace Tests\Feature\Http\App;

use App\Enums\CatalogMappingField;
use App\Models\CatalogImport;
use App\Models\Client;
use App\Models\User;
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
            ['title', 'price', 'currency', 'source', 'description', 'sold_at'],
            CatalogMappingField::allKeys()
        );
        $this->assertSame(
            ['title', 'price', 'currency', 'source'],
            CatalogMappingField::requiredKeys()
        );
        $this->assertSame(
            ['description', 'sold_at'],
            CatalogMappingField::optionalKeys()
        );
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
}
