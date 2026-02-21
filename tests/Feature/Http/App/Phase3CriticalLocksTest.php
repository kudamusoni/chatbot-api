<?php

namespace Tests\Feature\Http\App;

use App\Enums\ConversationEventType;
use App\Enums\ValuationStatus;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\ConversationMessage;
use App\Models\Lead;
use App\Models\User;
use App\Models\Valuation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase3CriticalLocksTest extends TestCase
{
    use RefreshDatabase;

    public function test_support_admin_gets_masked_pii_in_lead_detail_and_export(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        [$conversation] = Conversation::createWithToken($client->id);

        $lead = Lead::create([
            'conversation_id' => $conversation->id,
            'client_id' => $client->id,
            'name' => 'John Smith',
            'email' => 'john.smith@gmail.com',
            'email_hash' => hash('sha256', 'john.smith@gmail.com'),
            'phone_raw' => '+447700900123',
            'phone_normalized' => '+447700900123',
            'phone_hash' => hash('sha256', '+447700900123'),
            'status' => 'REQUESTED',
        ]);

        $support = User::factory()->create(['platform_role' => 'support_admin']);

        $this->actingAs($support, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->getJson('/app/leads/' . $lead->id)
            ->assertOk()
            ->assertJsonPath('data.name', 'Jo*** Sm***')
            ->assertJsonPath('data.email', 'jo***@gm***.com')
            ->assertJsonPath('data.phone', '+44******0123');

        $export = $this->actingAs($support, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->get('/app/leads/export')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('jo***@gm***.com', (string) $export);
        $this->assertStringNotContainsString('john.smith@gmail.com', (string) $export);
    }

    public function test_super_admin_gets_full_pii_in_lead_detail_and_export(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        [$conversation] = Conversation::createWithToken($client->id);

        $lead = Lead::create([
            'conversation_id' => $conversation->id,
            'client_id' => $client->id,
            'name' => 'John Smith',
            'email' => 'john.smith@gmail.com',
            'email_hash' => hash('sha256', 'john.smith@gmail.com'),
            'phone_raw' => '+447700900123',
            'phone_normalized' => '+447700900123',
            'phone_hash' => hash('sha256', '+447700900123'),
            'status' => 'REQUESTED',
        ]);

        $super = User::factory()->create(['platform_role' => 'super_admin']);

        $this->actingAs($super, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->getJson('/app/leads/' . $lead->id)
            ->assertOk()
            ->assertJsonPath('data.name', 'John Smith')
            ->assertJsonPath('data.email', 'john.smith@gmail.com')
            ->assertJsonPath('data.phone', '+447700900123');

        $export = $this->actingAs($super, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->get('/app/leads/export')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('john.smith@gmail.com', (string) $export);
    }

    public function test_conversation_messages_are_ordered_by_event_id_asc(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $viewer = User::factory()->create();
        $viewer->clients()->attach($client->id, ['role' => 'viewer']);

        [$conversation] = Conversation::createWithToken($client->id);

        $event1 = ConversationEvent::create([
            'conversation_id' => $conversation->id,
            'client_id' => $client->id,
            'type' => ConversationEventType::USER_MESSAGE_CREATED,
            'payload' => ['content' => 'one'],
        ]);
        $event2 = ConversationEvent::create([
            'conversation_id' => $conversation->id,
            'client_id' => $client->id,
            'type' => ConversationEventType::ASSISTANT_MESSAGE_CREATED,
            'payload' => ['content' => 'two'],
        ]);
        $event3 = ConversationEvent::create([
            'conversation_id' => $conversation->id,
            'client_id' => $client->id,
            'type' => ConversationEventType::USER_MESSAGE_CREATED,
            'payload' => ['content' => 'three'],
        ]);

        // Insert out of sequence; endpoint must still order by event_id asc.
        ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'client_id' => $client->id,
            'event_id' => $event3->id,
            'role' => 'user',
            'content' => 'm3',
        ]);
        ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'client_id' => $client->id,
            'event_id' => $event1->id,
            'role' => 'user',
            'content' => 'm1',
        ]);
        ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'client_id' => $client->id,
            'event_id' => $event2->id,
            'role' => 'assistant',
            'content' => 'm2',
        ]);

        $response = $this->actingAs($viewer, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->getJson('/app/conversations/' . $conversation->id . '/messages')
            ->assertOk();

        $this->assertSame(['m1', 'm2', 'm3'], array_column($response->json('data'), 'content'));
    }

    public function test_valuations_include_currency_and_minor_unit_ints(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $viewer = User::factory()->create();
        $viewer->clients()->attach($client->id, ['role' => 'viewer']);

        [$conversation] = Conversation::createWithToken($client->id);

        $valuation = Valuation::create([
            'conversation_id' => $conversation->id,
            'client_id' => $client->id,
            'status' => ValuationStatus::COMPLETED,
            'snapshot_hash' => hash('sha256', 'abc'),
            'input_snapshot' => ['maker' => 'Acme', 'currency' => 'USD'],
            'result' => [
                'count' => 12,
                'median' => 15000,
                'range' => ['low' => 9500, 'high' => 22000],
                'confidence' => 2,
                'signals_used' => ['sold' => 3, 'asking' => 9, 'estimates' => 0],
            ],
        ]);

        $list = $this->actingAs($viewer, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->getJson('/app/valuations')
            ->assertOk();

        $list->assertJsonPath('data.0.currency', 'USD');
        $this->assertIsInt($list->json('data.0.median'));
        $this->assertIsInt($list->json('data.0.range_low'));
        $this->assertIsInt($list->json('data.0.range_high'));
        $this->assertGreaterThanOrEqual(0, (float) $list->json('data.0.confidence'));
        $this->assertLessThanOrEqual(1, (float) $list->json('data.0.confidence'));

        $detail = $this->actingAs($viewer, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->getJson('/app/valuations/' . $valuation->id)
            ->assertOk();

        $detail->assertJsonPath('data.currency', 'USD');
        $this->assertIsInt($detail->json('data.median'));
        $this->assertIsInt($detail->json('data.range_low'));
        $this->assertIsInt($detail->json('data.range_high'));
        $this->assertGreaterThanOrEqual(0, (float) $detail->json('data.confidence'));
        $this->assertLessThanOrEqual(1, (float) $detail->json('data.confidence'));
    }
}
