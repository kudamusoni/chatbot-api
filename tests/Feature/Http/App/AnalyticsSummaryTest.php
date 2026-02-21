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

class AnalyticsSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_can_access_summary_and_response_has_locked_shape(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $viewer = User::factory()->create();
        $viewer->clients()->attach($client->id, ['role' => 'viewer']);

        [$conversation] = Conversation::createWithToken($client->id);
        DB::table('conversations')->where('id', $conversation->id)->update(['last_activity_at' => CarbonImmutable::now('UTC')]);

        Valuation::create([
            'conversation_id' => $conversation->id,
            'client_id' => $client->id,
            'status' => 'COMPLETED',
            'snapshot_hash' => hash('sha256', 'a'),
            'input_snapshot' => [],
            'result' => [],
        ]);

        Lead::create([
            'conversation_id' => $conversation->id,
            'client_id' => $client->id,
            'name' => 'Lead',
            'email' => 'lead@example.com',
            'phone_raw' => '+447700900111',
            'phone_normalized' => '+447700900111',
            'status' => 'REQUESTED',
        ]);

        CatalogImport::create([
            'client_id' => $client->id,
            'created_by' => $viewer->id,
            'status' => 'COMPLETED',
            'attempt' => 1,
        ]);

        $response = $this->actingAs($viewer, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->getJson('/app/analytics/summary?range=today')
            ->assertOk();

        $response->assertJsonStructure([
            'range',
            'client' => ['id', 'name'],
            'from',
            'to',
            'conversations',
            'valuations',
            'leads',
            'catalog_imports',
        ]);

        $this->assertSame('today', $response->json('range'));
        $this->assertSame($client->id, $response->json('client.id'));
        $from = CarbonImmutable::parse((string) $response->json('from'))->utc();
        $to = CarbonImmutable::parse((string) $response->json('to'))->utc();
        $now = CarbonImmutable::now('UTC');

        $this->assertSame($now->startOfDay()->format('Y-m-d H:i:s'), $from->format('Y-m-d H:i:s'));
        $this->assertTrue($to->lessThanOrEqualTo($now->addSeconds(2)));
        $this->assertTrue($to->greaterThan($from));
    }

    public function test_summary_counts_are_tenant_scoped_and_use_last_activity_for_conversations(): void
    {
        $clientA = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $clientB = Client::create(['name' => 'Client B', 'slug' => 'client-b', 'settings' => []]);

        $viewer = User::factory()->create();
        $viewer->clients()->attach($clientA->id, ['role' => 'viewer']);

        [$convA] = Conversation::createWithToken($clientA->id);
        [$convB] = Conversation::createWithToken($clientB->id);

        $recent = CarbonImmutable::now('UTC')->subDays(1);
        $old = CarbonImmutable::now('UTC')->subDays(12);

        DB::table('conversations')->where('id', $convA->id)->update([
            'last_activity_at' => $recent,
            'created_at' => $old,
        ]);
        DB::table('conversations')->where('id', $convB->id)->update([
            'last_activity_at' => $recent,
            'created_at' => $recent,
        ]);

        Valuation::create([
            'conversation_id' => $convA->id,
            'client_id' => $clientA->id,
            'status' => 'COMPLETED',
            'snapshot_hash' => hash('sha256', 'va'),
            'input_snapshot' => [],
            'result' => [],
        ]);
        Valuation::create([
            'conversation_id' => $convB->id,
            'client_id' => $clientB->id,
            'status' => 'COMPLETED',
            'snapshot_hash' => hash('sha256', 'vb'),
            'input_snapshot' => [],
            'result' => [],
        ]);

        Lead::create([
            'conversation_id' => $convA->id,
            'client_id' => $clientA->id,
            'name' => 'A',
            'email' => 'a@example.com',
            'phone_raw' => '+447700900111',
            'phone_normalized' => '+447700900111',
            'status' => 'REQUESTED',
        ]);

        CatalogImport::create([
            'client_id' => $clientA->id,
            'created_by' => $viewer->id,
            'status' => 'COMPLETED',
            'attempt' => 1,
        ]);

        $response = $this->actingAs($viewer, 'web')
            ->withSession(['active_client_id' => $clientA->id])
            ->getJson('/app/analytics/summary?range=7d')
            ->assertOk();

        $this->assertSame(1, $response->json('conversations'));
        $this->assertSame(1, $response->json('valuations'));
        $this->assertSame(1, $response->json('leads'));
        $this->assertSame(1, $response->json('catalog_imports'));
    }
}
