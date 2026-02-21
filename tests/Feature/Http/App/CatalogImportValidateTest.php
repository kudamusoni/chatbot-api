<?php

namespace Tests\Feature\Http\App;

use App\Models\CatalogImport;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CatalogImportValidateTest extends TestCase
{
    use RefreshDatabase;

    public function test_validate_returns_stable_schema_and_config_limits(): void
    {
        config()->set('catalog.sample_rows', 25);
        config()->set('catalog.max_rows', 999);
        config()->set('catalog.max_bytes', 2048);
        config()->set('catalog.import_disk', 'local');

        Storage::fake('local');

        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $user = User::factory()->create();
        $user->clients()->attach($client->id, ['role' => 'admin']);

        $rows = ["name,email,phone"];
        for ($i = 1; $i <= 30; $i++) {
            $rows[] = "Name{$i},n{$i}@example.com,+447700900{$i}";
        }
        $path = 'imports/sample.csv';
        Storage::disk('local')->put($path, implode("\n", $rows));

        $import = CatalogImport::create([
            'client_id' => $client->id,
            'created_by' => $user->id,
            'status' => 'CREATED',
            'file_path' => $path,
        ]);

        $this->actingAs($user, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->postJson("/app/catalog-imports/{$import->id}/validate")
            ->assertOk()
            ->assertJsonStructure([
                'columns',
                'sample_rows',
                'suggested_mapping',
                'errors',
                'limits' => ['max_rows', 'max_bytes'],
            ])
            ->assertJsonPath('limits.max_rows', 999)
            ->assertJsonPath('limits.max_bytes', 2048)
            ->assertJsonCount(25, 'sample_rows');
    }
}
