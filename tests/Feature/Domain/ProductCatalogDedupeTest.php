<?php

namespace Tests\Feature\Domain;

use App\Enums\ProductSource;
use App\Models\Client;
use App\Models\ProductCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCatalogDedupeTest extends TestCase
{
    use RefreshDatabase;

    public function test_dedupe_index_blocks_duplicate_catalog_rows(): void
    {
        $client = Client::create([
            'name' => 'Client A',
            'slug' => 'client-a',
            'settings' => [],
        ]);

        ProductCatalog::create([
            'client_id' => $client->id,
            'title' => 'Rolex Submariner',
            'description' => null,
            'source' => ProductSource::SOLD,
            'price' => 125000,
            'currency' => 'GBP',
            'sold_at' => null,
        ]);

        $this->expectException(QueryException::class);

        ProductCatalog::create([
            'client_id' => $client->id,
            'title' => '  rolex   submariner  ',
            'description' => null,
            'source' => ProductSource::SOLD,
            'price' => 125000,
            'currency' => 'GBP',
            'sold_at' => null,
        ]);
    }
}
