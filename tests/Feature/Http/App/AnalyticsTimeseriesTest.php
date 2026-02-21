<?php

namespace Tests\Feature\Http\App;

use App\Models\CatalogImport;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\User;
use App\Models\Valuation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AnalyticsTimeseriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_timeseries_returns_utc_day_buckets_sorted_ascending_with_zero_fill(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $viewer = User::factory()->create();
        $viewer->clients()->attach($client->id, ['role' => 'viewer']);

        [$conversation] = Conversation::createWithToken($client->id);
        $day1 = CarbonImmutable::now('UTC')->subDays(5)->setTime(10, 0);
        $day2 = CarbonImmutable::now('UTC')->subDays(3)->setTime(10, 0);

        DB::table('conversations')->where('id', $conversation->id)->update(['last_activity_at' => $day1]);

        Valuation::create([
            'conversation_id' => $conversation->id,
            'client_id' => $client->id,
            'status' => 'COMPLETED',
            'snapshot_hash' => hash('sha256', 'vt'),
            'input_snapshot' => [],
            'result' => [],
        ]);
        DB::table('valuations')->where('conversation_id', $conversation->id)->update(['created_at' => $day2]);

        Lead::create([
            'conversation_id' => $conversation->id,
            'client_id' => $client->id,
            'name' => 'Lead',
            'email' => 'lead@example.com',
            'phone_raw' => '+447700900111',
            'phone_normalized' => '+447700900111',
            'status' => 'REQUESTED',
        ]);
        DB::table('leads')->where('conversation_id', $conversation->id)->update(['created_at' => $day2]);

        CatalogImport::create([
            'client_id' => $client->id,
            'created_by' => $viewer->id,
            'status' => 'COMPLETED',
            'attempt' => 1,
        ]);
        DB::table('catalog_imports')->where('client_id', $client->id)->update(['created_at' => $day2]);

        $response = $this->actingAs($viewer, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->getJson('/app/analytics/timeseries?range=7d')
            ->assertOk();

        $response->assertJsonStructure([
            'range',
            'client' => ['id', 'name'],
            'from',
            'to',
            'data' => [
                ['date', 'conversations', 'valuations', 'leads', 'catalog_imports'],
            ],
        ]);

        $dates = array_column($response->json('data'), 'date');
        $sorted = $dates;
        sort($sorted);
        $this->assertSame($sorted, $dates);

        $this->assertContains($day1->format('Y-m-d'), $dates);
        $this->assertContains($day2->format('Y-m-d'), $dates);

        $target = collect($response->json('data'))->firstWhere('date', $day1->format('Y-m-d'));
        $this->assertSame(1, $target['conversations']);

        $zeroDay = collect($response->json('data'))->firstWhere('date', CarbonImmutable::now('UTC')->subDays(6)->format('Y-m-d'));
        $this->assertNotNull($zeroDay);
        $this->assertSame(0, $zeroDay['valuations']);
    }
}
