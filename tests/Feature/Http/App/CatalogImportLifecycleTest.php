<?php

namespace Tests\Feature\Http\App;

use App\Jobs\RunCatalogImportJob;
use App\Models\CatalogImport;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CatalogImportLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_upload_start_show_and_list_import_lifecycle(): void
    {
        config()->set('catalog.import_disk', 'local');
        Storage::fake('local');
        Queue::fake();

        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $user = User::factory()->create();
        $user->clients()->attach($client->id, ['role' => 'admin']);

        $create = $this->actingAs($user, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->postJson('/app/catalog-imports')
            ->assertCreated()
            ->assertJsonPath('ok', true);

        $importId = (string) $create->json('import.id');

        $upload = $this->actingAs($user, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->postJson("/app/catalog-imports/{$importId}/upload", [
                'file' => UploadedFile::fake()->createWithContent(
                    'catalog.csv',
                    "title,price,currency,source\nRolex,1000,GBP,sold\n"
                ),
            ])
            ->assertOk();

        $this->assertSame('UPLOADED', $upload->json('status'));

        $this->actingAs($user, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->postJson("/app/catalog-imports/{$importId}/validate")
            ->assertOk();

        $this->actingAs($user, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->postJson("/app/catalog-imports/{$importId}/start")
            ->assertStatus(422);

        $this->actingAs($user, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->postJson("/app/catalog-imports/{$importId}/start", [
                'mapping' => [
                    'title' => 'title',
                    'price' => 'price',
                    'currency' => 'currency',
                    'source' => 'source',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'QUEUED');

        Queue::assertPushed(RunCatalogImportJob::class);
        $this->assertSame([
            'title' => 'title',
            'price' => 'price',
            'currency' => 'currency',
            'source' => 'source',
        ], CatalogImport::findOrFail($importId)->mapping);

        $this->actingAs($user, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->getJson("/app/catalog-imports/{$importId}")
            ->assertOk()
            ->assertJsonPath('import.id', $importId);

        $this->actingAs($user, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->getJson('/app/catalog-imports')
            ->assertOk()
            ->assertJsonPath('data.0.id', $importId);
    }
}
