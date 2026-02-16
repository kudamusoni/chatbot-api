<?php

namespace Tests\Feature\Domain;

use App\Enums\ProductSource;
use App\Models\Client;
use App\Models\ProductCatalog;
use App\Services\ValuationEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ValuationEngineTest extends TestCase
{
    use RefreshDatabase;

    private ValuationEngine $engine;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new ValuationEngine();
        $this->client = Client::create([
            'name' => 'Test Client',
            'slug' => 'test-client-' . uniqid(),
        ]);
    }

    public function test_zero_comps_returns_exact_zero_match_contract(): void
    {
        $result = $this->engine->compute($this->client->id, [
            'maker' => 'NonExistentMaker',
            'material' => 'NonExistentMaterial',
        ]);

        $this->assertEquals([
            'count' => 0,
            'range' => null,
            'median' => null,
            'confidence' => 0,
            'data_quality' => 'internal',
            'signals_used' => [
                'sold' => 0,
                'asking' => 0,
                'estimates' => 0,
            ],
            'matched_comps_sample' => [],
        ], $result);
    }

    public function test_empty_snapshot_returns_zero_match(): void
    {
        $this->createComp(['title' => 'Royal Doulton Vase', 'price' => 10000]);

        $result = $this->engine->compute($this->client->id, []);

        $this->assertEquals(0, $result['count']);
        $this->assertNull($result['median']);
    }

    public function test_median_calculation_with_odd_count(): void
    {
        // 3 items: 100, 200, 300 -> median = 200
        $this->createComp(['title' => 'Royal Doulton A', 'price' => 10000]);
        $this->createComp(['title' => 'Royal Doulton B', 'price' => 20000]);
        $this->createComp(['title' => 'Royal Doulton C', 'price' => 30000]);

        $result = $this->engine->compute($this->client->id, ['maker' => 'Royal Doulton']);

        $this->assertEquals(3, $result['count']);
        $this->assertEquals(20000, $result['median']); // £200.00 in pence
    }

    public function test_median_calculation_with_even_count(): void
    {
        // 4 items: 100, 200, 300, 400 -> median = (200+300)/2 = 250
        $this->createComp(['title' => 'Royal Doulton A', 'price' => 10000]);
        $this->createComp(['title' => 'Royal Doulton B', 'price' => 20000]);
        $this->createComp(['title' => 'Royal Doulton C', 'price' => 30000]);
        $this->createComp(['title' => 'Royal Doulton D', 'price' => 40000]);

        $result = $this->engine->compute($this->client->id, ['maker' => 'Royal Doulton']);

        $this->assertEquals(4, $result['count']);
        $this->assertEquals(25000, $result['median']); // £250.00 in pence
    }

    public function test_range_uses_min_max_for_small_samples(): void
    {
        // 3 items (< 5): should use min-max range
        $this->createComp(['title' => 'Royal Doulton A', 'price' => 10000]);
        $this->createComp(['title' => 'Royal Doulton B', 'price' => 20000]);
        $this->createComp(['title' => 'Royal Doulton C', 'price' => 30000]);

        $result = $this->engine->compute($this->client->id, ['maker' => 'Royal Doulton']);

        $this->assertEquals(3, $result['count']);
        $this->assertEquals(['low' => 10000, 'high' => 30000], $result['range']);
    }

    public function test_range_uses_percentiles_for_larger_samples(): void
    {
        // 8 items: should use p25-p75 range
        // Sorted: 100, 150, 200, 250, 300, 350, 400, 450
        // p25 index = floor(8 * 0.25) = 2 -> 200
        // p75 index = floor(8 * 0.75) = 6 -> 400
        $this->createComp(['title' => 'Royal Doulton A', 'price' => 10000]);
        $this->createComp(['title' => 'Royal Doulton B', 'price' => 15000]);
        $this->createComp(['title' => 'Royal Doulton C', 'price' => 20000]);
        $this->createComp(['title' => 'Royal Doulton D', 'price' => 25000]);
        $this->createComp(['title' => 'Royal Doulton E', 'price' => 30000]);
        $this->createComp(['title' => 'Royal Doulton F', 'price' => 35000]);
        $this->createComp(['title' => 'Royal Doulton G', 'price' => 40000]);
        $this->createComp(['title' => 'Royal Doulton H', 'price' => 45000]);

        $result = $this->engine->compute($this->client->id, ['maker' => 'Royal Doulton']);

        $this->assertEquals(8, $result['count']);
        $this->assertEquals(['low' => 20000, 'high' => 40000], $result['range']);
    }

    public function test_confidence_zero_with_few_comps_no_sold(): void
    {
        // 2 asking items: count < 5, sold_count = 0 -> confidence = 0
        $this->createComp(['title' => 'Royal Doulton A', 'price' => 10000, 'source' => ProductSource::ASKING]);
        $this->createComp(['title' => 'Royal Doulton B', 'price' => 20000, 'source' => ProductSource::ASKING]);

        $result = $this->engine->compute($this->client->id, ['maker' => 'Royal Doulton']);

        $this->assertEquals(0, $result['confidence']);
        $this->assertEquals(2, $result['signals_used']['asking']);
        $this->assertEquals(0, $result['signals_used']['sold']);
    }

    public function test_confidence_one_with_sold_item(): void
    {
        // 2 items, 1 sold: count < 5 (+0), sold_count >= 1 (+1) -> confidence = 1
        $this->createComp(['title' => 'Royal Doulton A', 'price' => 10000, 'source' => ProductSource::SOLD]);
        $this->createComp(['title' => 'Royal Doulton B', 'price' => 20000, 'source' => ProductSource::ASKING]);

        $result = $this->engine->compute($this->client->id, ['maker' => 'Royal Doulton']);

        $this->assertEquals(1, $result['confidence']);
    }

    public function test_confidence_two_with_five_items_and_sold(): void
    {
        // 5 items, 1 sold: count >= 5 (+1), sold_count >= 1 (+1) -> confidence = 2
        $this->createComp(['title' => 'Royal Doulton A', 'price' => 10000, 'source' => ProductSource::SOLD]);
        $this->createComp(['title' => 'Royal Doulton B', 'price' => 15000, 'source' => ProductSource::ASKING]);
        $this->createComp(['title' => 'Royal Doulton C', 'price' => 20000, 'source' => ProductSource::ASKING]);
        $this->createComp(['title' => 'Royal Doulton D', 'price' => 25000, 'source' => ProductSource::ESTIMATE]);
        $this->createComp(['title' => 'Royal Doulton E', 'price' => 30000, 'source' => ProductSource::ASKING]);

        $result = $this->engine->compute($this->client->id, ['maker' => 'Royal Doulton']);

        $this->assertEquals(2, $result['confidence']);
    }

    public function test_confidence_three_with_three_sold_items(): void
    {
        // 5 items, 3 sold: count >= 5 (+1), sold_count >= 1 (+1), sold_count >= 3 (+1) -> confidence = 3
        $this->createComp(['title' => 'Royal Doulton A', 'price' => 10000, 'source' => ProductSource::SOLD]);
        $this->createComp(['title' => 'Royal Doulton B', 'price' => 15000, 'source' => ProductSource::SOLD]);
        $this->createComp(['title' => 'Royal Doulton C', 'price' => 20000, 'source' => ProductSource::SOLD]);
        $this->createComp(['title' => 'Royal Doulton D', 'price' => 25000, 'source' => ProductSource::ASKING]);
        $this->createComp(['title' => 'Royal Doulton E', 'price' => 30000, 'source' => ProductSource::ASKING]);

        $result = $this->engine->compute($this->client->id, ['maker' => 'Royal Doulton']);

        $this->assertEquals(3, $result['confidence']);
    }

    public function test_confidence_three_with_fifteen_items(): void
    {
        // 15 items, 1 sold: count >= 5 (+1), sold_count >= 1 (+1), count >= 15 (+1) -> confidence = 3
        for ($i = 1; $i <= 14; $i++) {
            $this->createComp([
                'title' => "Royal Doulton Item {$i}",
                'price' => $i * 1000,
                'source' => ProductSource::ASKING,
            ]);
        }
        $this->createComp(['title' => 'Royal Doulton Sold', 'price' => 15000, 'source' => ProductSource::SOLD]);

        $result = $this->engine->compute($this->client->id, ['maker' => 'Royal Doulton']);

        $this->assertEquals(15, $result['count']);
        $this->assertEquals(3, $result['confidence']);
    }

    public function test_signals_used_counts_correctly(): void
    {
        $this->createComp(['title' => 'Royal Doulton A', 'price' => 10000, 'source' => ProductSource::SOLD]);
        $this->createComp(['title' => 'Royal Doulton B', 'price' => 15000, 'source' => ProductSource::SOLD]);
        $this->createComp(['title' => 'Royal Doulton C', 'price' => 20000, 'source' => ProductSource::ASKING]);
        $this->createComp(['title' => 'Royal Doulton D', 'price' => 25000, 'source' => ProductSource::ASKING]);
        $this->createComp(['title' => 'Royal Doulton E', 'price' => 30000, 'source' => ProductSource::ASKING]);
        $this->createComp(['title' => 'Royal Doulton F', 'price' => 35000, 'source' => ProductSource::ESTIMATE]);

        $result = $this->engine->compute($this->client->id, ['maker' => 'Royal Doulton']);

        $this->assertEquals([
            'sold' => 2,
            'asking' => 3,
            'estimates' => 1,
        ], $result['signals_used']);
    }

    public function test_filters_by_currency(): void
    {
        // Create GBP and USD items
        $this->createComp(['title' => 'Royal Doulton GBP', 'price' => 10000, 'currency' => 'GBP']);
        $this->createComp(['title' => 'Royal Doulton USD', 'price' => 15000, 'currency' => 'USD']);

        // Default currency is GBP
        $result = $this->engine->compute($this->client->id, ['maker' => 'Royal Doulton']);

        $this->assertEquals(1, $result['count']);
        $this->assertEquals(10000, $result['median']);

        // Explicit USD
        $resultUsd = $this->engine->compute($this->client->id, ['maker' => 'Royal Doulton'], 'USD');

        $this->assertEquals(1, $resultUsd['count']);
        $this->assertEquals(15000, $resultUsd['median']);
    }

    public function test_tenant_isolation(): void
    {
        $otherClient = Client::create([
            'name' => 'Other Client',
            'slug' => 'other-client-' . uniqid(),
        ]);

        // Create item for other client
        ProductCatalog::create([
            'client_id' => $otherClient->id,
            'title' => 'Royal Doulton Other Client',
            'price' => 99999,
            'source' => ProductSource::SOLD,
            'currency' => 'GBP',
        ]);

        // Create item for our client
        $this->createComp(['title' => 'Royal Doulton Our Client', 'price' => 10000]);

        $result = $this->engine->compute($this->client->id, ['maker' => 'Royal Doulton']);

        // Should only find our client's item
        $this->assertEquals(1, $result['count']);
        $this->assertEquals(10000, $result['median']);
    }

    public function test_multiple_search_terms_match(): void
    {
        $this->createComp(['title' => 'Royal Doulton Porcelain Vase', 'price' => 10000]);
        $this->createComp(['title' => 'Wedgwood Porcelain Plate', 'price' => 20000]);
        $this->createComp(['title' => 'Royal Worcester Cup', 'price' => 30000]);

        // Search for "Royal" and "Porcelain" - should match items containing either
        $result = $this->engine->compute($this->client->id, [
            'maker' => 'Royal',
            'material' => 'Porcelain',
        ]);

        // Should match: Royal Doulton Porcelain Vase, Wedgwood Porcelain Plate, Royal Worcester Cup
        $this->assertEquals(3, $result['count']);
    }

    public function test_data_quality_is_always_internal(): void
    {
        $this->createComp(['title' => 'Royal Doulton', 'price' => 10000]);

        $result = $this->engine->compute($this->client->id, ['maker' => 'Royal Doulton']);

        $this->assertEquals('internal', $result['data_quality']);
    }

    public function test_matches_using_any_snapshot_keys_not_just_predefined_fields(): void
    {
        $this->createComp([
            'title' => 'Royal Doulton Tea Set - Bunnykins',
            'description' => 'Children tea set in excellent condition',
            'price' => 12000,
            'source' => ProductSource::SOLD,
        ]);

        $result = $this->engine->compute($this->client->id, [
            'item_type' => 'Royal Doulton Tea Set',
            'condition' => 'New',
        ]);

        $this->assertSame(1, $result['count']);
        $this->assertSame(12000, $result['median']);
    }

    public function test_matched_comps_sample_returns_up_to_five_items(): void
    {
        // Create 7 items
        for ($i = 1; $i <= 7; $i++) {
            $this->createComp([
                'title' => "Royal Doulton Item {$i}",
                'price' => $i * 1000,
                'source' => ProductSource::ASKING,
            ]);
        }

        $result = $this->engine->compute($this->client->id, ['maker' => 'Royal Doulton']);

        $this->assertEquals(7, $result['count']);
        $this->assertArrayHasKey('matched_comps_sample', $result);
        $this->assertCount(5, $result['matched_comps_sample']);

        // Each sample should have title, price, source
        foreach ($result['matched_comps_sample'] as $sample) {
            $this->assertArrayHasKey('title', $sample);
            $this->assertArrayHasKey('price', $sample);
            $this->assertArrayHasKey('source', $sample);
        }
    }

    public function test_matched_comps_sample_includes_source_information(): void
    {
        // Create items with different sources
        $this->createComp(['title' => 'Royal Doulton Asking 1', 'price' => 50000, 'source' => ProductSource::ASKING]);
        $this->createComp(['title' => 'Royal Doulton Sold 1', 'price' => 10000, 'source' => ProductSource::SOLD]);
        $this->createComp(['title' => 'Royal Doulton Estimate 1', 'price' => 30000, 'source' => ProductSource::ESTIMATE]);

        $result = $this->engine->compute($this->client->id, ['maker' => 'Royal Doulton']);

        $this->assertCount(3, $result['matched_comps_sample']);

        // All sources should be represented
        $sources = array_column($result['matched_comps_sample'], 'source');
        $this->assertContains('sold', $sources);
        $this->assertContains('asking', $sources);
        $this->assertContains('estimate', $sources);

        // Each sample should have valid structure
        foreach ($result['matched_comps_sample'] as $sample) {
            $this->assertNotEmpty($sample['title']);
            $this->assertIsInt($sample['price']);
            $this->assertContains($sample['source'], ['sold', 'asking', 'estimate']);
        }
    }

    /**
     * Helper to create a product catalog item.
     */
    private function createComp(array $attributes): ProductCatalog
    {
        return ProductCatalog::create(array_merge([
            'client_id' => $this->client->id,
            'title' => 'Test Item',
            'source' => ProductSource::ASKING,
            'price' => 10000,
            'currency' => 'GBP',
        ], $attributes));
    }
}
