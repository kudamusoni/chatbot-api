<?php

namespace Tests\Feature\Http\App;

use App\Models\Client;
use App\Models\ProductCatalog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductCatalogEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_can_list_products_for_active_client(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $viewer = User::factory()->create();
        $viewer->clients()->attach($client->id, ['role' => 'viewer']);

        for ($i = 1; $i <= 3; $i++) {
            $product = ProductCatalog::create([
                'client_id' => $client->id,
                'title' => "Product {$i}",
                'description' => "Description {$i}",
                'source' => $i === 3 ? 'estimate' : 'sold',
                'price' => 10000 + $i,
                'low_estimate' => $i === 3 ? 9000 : null,
                'high_estimate' => $i === 3 ? 12000 : null,
                'currency' => 'GBP',
            ]);

            DB::table('product_catalog')->where('id', $product->id)->update([
                'created_at' => Carbon::parse('2026-02-01 00:00:00', 'UTC')->addMinutes($i),
                'updated_at' => Carbon::parse('2026-02-01 00:00:00', 'UTC')->addMinutes($i),
            ]);
        }

        $response = $this->actingAs($viewer, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->getJson('/app/products?page=1&per_page=20')
            ->assertOk();

        $response->assertJsonPath('meta.default_sort', 'created_at:desc');
        $response->assertJsonCount(3, 'data');
        $this->assertSame('Product 3', $response->json('data.0.title'));
        $this->assertSame(9000, $response->json('data.0.low_estimate'));
        $this->assertSame(12000, $response->json('data.0.high_estimate'));
    }

    public function test_products_list_applies_q_source_and_range_filters(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $viewer = User::factory()->create();
        $viewer->clients()->attach($client->id, ['role' => 'viewer']);

        $match = ProductCatalog::create([
            'client_id' => $client->id,
            'title' => 'Lead 9 Lamp',
            'description' => 'Target product',
            'source' => 'sold',
            'price' => 9500,
            'currency' => 'GBP',
        ]);

        $wrongSource = ProductCatalog::create([
            'client_id' => $client->id,
            'title' => 'Lead 9 Estimate',
            'description' => 'Wrong source',
            'source' => 'estimate',
            'price' => 9600,
            'currency' => 'GBP',
        ]);

        $old = ProductCatalog::create([
            'client_id' => $client->id,
            'title' => 'Lead 9 Old Sold',
            'description' => 'Too old',
            'source' => 'sold',
            'price' => 9700,
            'currency' => 'GBP',
        ]);

        DB::table('product_catalog')->where('id', $match->id)->update([
            'created_at' => Carbon::now('UTC')->subHours(2),
            'updated_at' => Carbon::now('UTC')->subHours(2),
        ]);
        DB::table('product_catalog')->where('id', $wrongSource->id)->update([
            'created_at' => Carbon::now('UTC')->subHours(2),
            'updated_at' => Carbon::now('UTC')->subHours(2),
        ]);
        DB::table('product_catalog')->where('id', $old->id)->update([
            'created_at' => Carbon::now('UTC')->subDays(3),
            'updated_at' => Carbon::now('UTC')->subDays(3),
        ]);

        $response = $this->actingAs($viewer, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->getJson('/app/products?page=1&per_page=20&q=Lead+9&source=sold&range=today')
            ->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $match->id);
    }

    public function test_products_detail_is_tenant_scoped(): void
    {
        $clientA = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $clientB = Client::create(['name' => 'Client B', 'slug' => 'client-b', 'settings' => []]);
        $viewer = User::factory()->create();
        $viewer->clients()->attach($clientA->id, ['role' => 'viewer']);

        $productA = ProductCatalog::create([
            'client_id' => $clientA->id,
            'title' => 'A Product',
            'description' => null,
            'source' => 'sold',
            'price' => 10000,
            'currency' => 'GBP',
        ]);

        $productB = ProductCatalog::create([
            'client_id' => $clientB->id,
            'title' => 'B Product',
            'description' => null,
            'source' => 'sold',
            'price' => 20000,
            'currency' => 'GBP',
        ]);

        $this->actingAs($viewer, 'web')
            ->withSession(['active_client_id' => $clientA->id])
            ->getJson("/app/products/{$productA->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $productA->id);

        $this->actingAs($viewer, 'web')
            ->withSession(['active_client_id' => $clientA->id])
            ->getJson("/app/products/{$productB->id}")
            ->assertNotFound();
    }
}
