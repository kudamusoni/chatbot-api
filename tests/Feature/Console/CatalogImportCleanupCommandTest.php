<?php

namespace Tests\Feature\Console;

use App\Models\CatalogImport;
use App\Models\Client;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CatalogImportCleanupCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_cleanup_abandoned_dry_run_does_not_delete_rows(): void
    {
        Storage::fake('local');
        config()->set('catalog.import_disk', 'local');

        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $user = User::factory()->create();
        $import = CatalogImport::create([
            'client_id' => $client->id,
            'created_by' => $user->id,
            'status' => 'CREATED',
            'attempt' => 1,
        ]);

        DB::table('catalog_imports')->where('id', $import->id)->update([
            'updated_at' => CarbonImmutable::now('UTC')->subHours(48),
        ]);

        $this->artisan('catalog-imports:cleanup-abandoned', ['--hours' => 24, '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertDatabaseHas('catalog_imports', ['id' => $import->id]);
    }

    public function test_cleanup_abandoned_deletes_only_stale_draft_rows(): void
    {
        Storage::fake('local');
        config()->set('catalog.import_disk', 'local');

        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $user = User::factory()->create();

        $staleCreated = CatalogImport::create([
            'client_id' => $client->id,
            'created_by' => $user->id,
            'status' => 'CREATED',
            'attempt' => 1,
            'file_path' => 'catalog-imports/' . $client->id . '/stale-created.csv',
        ]);
        Storage::disk('local')->put($staleCreated->file_path, "title,price\nA,100\n");

        $staleValidated = CatalogImport::create([
            'client_id' => $client->id,
            'created_by' => $user->id,
            'status' => 'VALIDATED',
            'attempt' => 1,
        ]);

        $recentCreated = CatalogImport::create([
            'client_id' => $client->id,
            'created_by' => $user->id,
            'status' => 'CREATED',
            'attempt' => 1,
        ]);

        $completed = CatalogImport::create([
            'client_id' => $client->id,
            'created_by' => $user->id,
            'status' => 'COMPLETED',
            'attempt' => 1,
        ]);

        DB::table('catalog_imports')->whereIn('id', [$staleCreated->id, $staleValidated->id])->update([
            'updated_at' => CarbonImmutable::now('UTC')->subHours(48),
        ]);
        DB::table('catalog_imports')->where('id', $recentCreated->id)->update([
            'updated_at' => CarbonImmutable::now('UTC')->subHours(2),
        ]);
        DB::table('catalog_imports')->where('id', $completed->id)->update([
            'updated_at' => CarbonImmutable::now('UTC')->subHours(72),
        ]);

        $this->artisan('catalog-imports:cleanup-abandoned', ['--hours' => 24])
            ->assertExitCode(0);

        $this->assertDatabaseMissing('catalog_imports', ['id' => $staleCreated->id]);
        $this->assertDatabaseMissing('catalog_imports', ['id' => $staleValidated->id]);
        $this->assertDatabaseHas('catalog_imports', ['id' => $recentCreated->id]);
        $this->assertDatabaseHas('catalog_imports', ['id' => $completed->id]);
        $this->assertFalse(Storage::disk('local')->exists('catalog-imports/' . $client->id . '/stale-created.csv'));
    }
}
