<?php

namespace Tests\Feature\Http\Widget;

use App\Enums\ConversationEventType;
use App\Enums\ConversationState;
use App\Models\ConversationEvent;
use App\Models\Lead;
use App\Models\Valuation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithConversations;
use Tests\TestCase;

class AppraisalConfirmTest extends TestCase
{
    use RefreshDatabase, InteractsWithConversations;

    public function test_confirm_emits_confirmed_and_valuation_requested(): void
    {
        // Fake the bus to prevent RunValuationJob from executing synchronously
        Bus::fake();

        $client = $this->makeClient();
        [$conversation, $token] = $this->makeConversation($client, [
            'state' => ConversationState::APPRAISAL_CONFIRM,
            'appraisal_snapshot' => [
                'maker' => 'Royal Doulton',
                'age' => 'circa 1950',
            ],
        ]);
        $lead = Lead::create([
            'conversation_id' => $conversation->id,
            'client_id' => $client->id,
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'email_hash' => hash('sha256', 'jane@example.com'),
            'phone_raw' => '+447700900000',
            'phone_normalized' => '+447700900000',
            'phone_hash' => hash('sha256', '+447700900000'),
            'status' => 'REQUESTED',
        ]);
        $conversation->update(['valuation_contact_lead_id' => $lead->id]);

        $actionId = (string) Str::uuid();

        $response = $this->postJson('/api/widget/appraisal/confirm', [
            'client_id' => $client->id,
            'session_token' => $token,
            'action_id' => $actionId,
            'confirm' => true,
        ]);

        $response->assertOk()->assertJson(['ok' => true]);

        $events = ConversationEvent::where('conversation_id', $conversation->id)
            ->orderBy('id')
            ->get();

        $this->assertTrue($events->contains(fn ($e) => $e->type === ConversationEventType::APPRAISAL_CONFIRMED));
        $this->assertTrue($events->contains(fn ($e) => $e->type === ConversationEventType::VALUATION_REQUESTED));

        $conversation->refresh();
        $this->assertEquals(ConversationState::VALUATION_RUNNING, $conversation->state);

        $this->assertSame(1, Valuation::where('conversation_id', $conversation->id)->count());
    }

    public function test_confirm_is_idempotent_by_action_id(): void
    {
        $client = $this->makeClient();
        [$conversation, $token] = $this->makeConversation($client, [
            'state' => ConversationState::APPRAISAL_CONFIRM,
            'appraisal_snapshot' => [
                'maker' => 'Rolex',
                'age' => '1970',
            ],
        ]);
        $lead = Lead::create([
            'conversation_id' => $conversation->id,
            'client_id' => $client->id,
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'email_hash' => hash('sha256', 'jane@example.com'),
            'phone_raw' => '+447700900000',
            'phone_normalized' => '+447700900000',
            'phone_hash' => hash('sha256', '+447700900000'),
            'status' => 'REQUESTED',
        ]);
        $conversation->update(['valuation_contact_lead_id' => $lead->id]);

        $actionId = (string) Str::uuid();
        $payload = [
            'client_id' => $client->id,
            'session_token' => $token,
            'action_id' => $actionId,
            'confirm' => true,
        ];

        $this->postJson('/api/widget/appraisal/confirm', $payload)->assertOk();
        $this->postJson('/api/widget/appraisal/confirm', $payload)->assertOk();

        $this->assertSame(1, ConversationEvent::where('conversation_id', $conversation->id)
            ->where('type', ConversationEventType::APPRAISAL_CONFIRMED)
            ->count());

        $this->assertSame(1, Valuation::where('conversation_id', $conversation->id)->count());
    }

    public function test_confirm_reemits_completed_when_same_snapshot_already_terminal(): void
    {
        $client = $this->makeClient();
        $snapshot = [
            'maker' => 'Rolex',
            'age' => '1970',
        ];
        $snapshotHash = Valuation::generateSnapshotHash($snapshot);
        [$conversation, $token] = $this->makeConversation($client, [
            'state' => ConversationState::APPRAISAL_CONFIRM,
            'appraisal_snapshot' => $snapshot,
        ]);
        $lead = Lead::create([
            'conversation_id' => $conversation->id,
            'client_id' => $client->id,
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'email_hash' => hash('sha256', 'jane@example.com'),
            'phone_raw' => '+447700900000',
            'phone_normalized' => '+447700900000',
            'phone_hash' => hash('sha256', '+447700900000'),
            'status' => 'REQUESTED',
        ]);
        $conversation->update(['valuation_contact_lead_id' => $lead->id]);

        ConversationEvent::create([
            'conversation_id' => $conversation->id,
            'client_id' => $client->id,
            'type' => ConversationEventType::VALUATION_REQUESTED,
            'payload' => [
                'snapshot_hash' => $snapshotHash,
                'input_snapshot' => $snapshot,
                'lead_id' => $lead->id,
            ],
            'correlation_id' => (string) Str::uuid(),
            'idempotency_key' => $snapshotHash,
        ]);

        Valuation::create([
            'conversation_id' => $conversation->id,
            'client_id' => $client->id,
            'status' => \App\Enums\ValuationStatus::COMPLETED,
            'snapshot_hash' => $snapshotHash,
            'input_snapshot' => $snapshot,
            'result' => ['count' => 5, 'median' => 5000, 'confidence' => 0.8],
        ]);

        $actionId = (string) Str::uuid();

        $response = $this->postJson('/api/widget/appraisal/confirm', [
            'client_id' => $client->id,
            'session_token' => $token,
            'action_id' => $actionId,
            'confirm' => true,
        ]);

        $response->assertOk()->assertJson(['ok' => true]);

        $this->assertTrue(
            ConversationEvent::where('conversation_id', $conversation->id)
                ->where('type', ConversationEventType::VALUATION_COMPLETED)
                ->where('payload->snapshot_hash', $snapshotHash)
                ->exists()
        );
    }

    public function test_token_from_other_client_is_rejected(): void
    {
        $clientA = $this->makeClient(['name' => 'Client A']);
        $clientB = $this->makeClient(['name' => 'Client B']);

        [$conversationA, $tokenA] = $this->makeConversation($clientA, [
            'state' => ConversationState::APPRAISAL_CONFIRM,
            'appraisal_snapshot' => ['maker' => 'Omega'],
        ]);

        $response = $this->postJson('/api/widget/appraisal/confirm', [
            'client_id' => $clientB->id,
            'session_token' => $tokenA,
            'action_id' => (string) Str::uuid(),
            'confirm' => true,
        ]);

        $response->assertUnauthorized();

        $this->assertSame(0, ConversationEvent::where('client_id', $clientA->id)->count());
        $this->assertSame(0, ConversationEvent::where('client_id', $clientB->id)->count());
        $this->assertSame(0, Valuation::where('client_id', $clientA->id)->count());
    }

    public function test_cancel_emits_cancelled_and_returns_state_to_chat(): void
    {
        $client = $this->makeClient();
        [$conversation, $token] = $this->makeConversation($client, [
            'state' => ConversationState::APPRAISAL_CONFIRM,
            'appraisal_snapshot' => [
                'maker' => 'Cartier',
                'age' => '1980',
            ],
        ]);

        $actionId = (string) Str::uuid();

        $response = $this->postJson('/api/widget/appraisal/confirm', [
            'client_id' => $client->id,
            'session_token' => $token,
            'action_id' => $actionId,
            'confirm' => false,
        ]);

        $response->assertOk()->assertJson(['ok' => true]);

        // Should emit APPRAISAL_CANCELLED event
        $this->assertTrue(
            ConversationEvent::where('conversation_id', $conversation->id)
                ->where('type', ConversationEventType::APPRAISAL_CANCELLED)
                ->exists()
        );

        // Should NOT emit APPRAISAL_CONFIRMED or VALUATION_REQUESTED
        $this->assertFalse(
            ConversationEvent::where('conversation_id', $conversation->id)
                ->where('type', ConversationEventType::APPRAISAL_CONFIRMED)
                ->exists()
        );
        $this->assertFalse(
            ConversationEvent::where('conversation_id', $conversation->id)
                ->where('type', ConversationEventType::VALUATION_REQUESTED)
                ->exists()
        );
        $this->assertTrue(
            ConversationEvent::where('conversation_id', $conversation->id)
                ->where('type', ConversationEventType::ASSISTANT_MESSAGE_CREATED)
                ->where('payload->content', 'Is there anything else that you needed info about?')
                ->exists()
        );

        // State should return to CHAT
        $conversation->refresh();
        $this->assertEquals(ConversationState::CHAT, $conversation->state);

        // No valuation should be created
        $this->assertSame(0, Valuation::where('conversation_id', $conversation->id)->count());
    }

    public function test_cancel_is_idempotent_by_action_id(): void
    {
        $client = $this->makeClient();
        [$conversation, $token] = $this->makeConversation($client, [
            'state' => ConversationState::APPRAISAL_CONFIRM,
            'appraisal_snapshot' => ['maker' => 'Patek Philippe'],
        ]);

        $actionId = (string) Str::uuid();
        $payload = [
            'client_id' => $client->id,
            'session_token' => $token,
            'action_id' => $actionId,
            'confirm' => false,
        ];

        // First cancel
        $response1 = $this->postJson('/api/widget/appraisal/confirm', $payload);
        $response1->assertOk();
        $firstLastEventId = $response1->json('last_event_id');

        // Retry cancel with same action_id
        $response2 = $this->postJson('/api/widget/appraisal/confirm', $payload);
        $response2->assertOk();
        $secondLastEventId = $response2->json('last_event_id');

        // Should return same last_event_id (no new event written)
        $this->assertSame($firstLastEventId, $secondLastEventId);

        // Should only have 1 APPRAISAL_CANCELLED event
        $this->assertSame(1, ConversationEvent::where('conversation_id', $conversation->id)
            ->where('type', ConversationEventType::APPRAISAL_CANCELLED)
            ->count());
        $this->assertSame(1, ConversationEvent::where('conversation_id', $conversation->id)
            ->where('type', ConversationEventType::ASSISTANT_MESSAGE_CREATED)
            ->where('payload->content', 'Is there anything else that you needed info about?')
            ->count());
    }

    public function test_cancel_token_from_other_client_is_rejected(): void
    {
        $clientA = $this->makeClient(['name' => 'Client A']);
        $clientB = $this->makeClient(['name' => 'Client B']);

        [$conversationA, $tokenA] = $this->makeConversation($clientA, [
            'state' => ConversationState::APPRAISAL_CONFIRM,
            'appraisal_snapshot' => ['maker' => 'Breguet'],
        ]);

        // Try to cancel Client A's conversation using Client B's ID
        $response = $this->postJson('/api/widget/appraisal/confirm', [
            'client_id' => $clientB->id,
            'session_token' => $tokenA,
            'action_id' => (string) Str::uuid(),
            'confirm' => false,
        ]);

        $response->assertUnauthorized();

        // No events should be created
        $this->assertSame(0, ConversationEvent::where('client_id', $clientA->id)->count());
        $this->assertSame(0, ConversationEvent::where('client_id', $clientB->id)->count());

        // State should remain unchanged
        $conversationA->refresh();
        $this->assertEquals(ConversationState::APPRAISAL_CONFIRM, $conversationA->state);
    }

    public function test_confirm_from_wrong_state_returns_error(): void
    {
        $client = $this->makeClient();
        [$conversation, $token] = $this->makeConversation($client, [
            'state' => ConversationState::CHAT, // Not in APPRAISAL_CONFIRM
        ]);

        $response = $this->postJson('/api/widget/appraisal/confirm', [
            'client_id' => $client->id,
            'session_token' => $token,
            'action_id' => (string) Str::uuid(),
            'confirm' => true,
        ]);

        $response->assertStatus(409)
            ->assertJson(['error' => 'Conversation is not awaiting confirmation']);

        // No events should be created
        $this->assertSame(0, ConversationEvent::where('conversation_id', $conversation->id)->count());
    }

    public function test_confirm_in_valuation_contact_capture_short_circuits_with_contact_required(): void
    {
        $client = $this->makeClient();
        [, $token] = $this->makeConversation($client, [
            'state' => ConversationState::VALUATION_CONTACT_CAPTURE,
        ]);

        $this->postJson('/api/widget/appraisal/confirm', [
            'client_id' => $client->id,
            'session_token' => $token,
            'action_id' => (string) Str::uuid(),
            'confirm' => true,
        ])->assertOk()
            ->assertJsonPath('blocked', true)
            ->assertJsonPath('reason_code', 'VALUATION_CONTACT_REQUIRED')
            ->assertJsonPath('pending_intent', null);
    }

    public function test_confirm_blocks_with_contact_required_when_contact_not_captured(): void
    {
        $client = $this->makeClient();
        [$conversation, $token] = $this->makeConversation($client, [
            'state' => ConversationState::APPRAISAL_CONFIRM,
            'appraisal_snapshot' => [
                'maker' => 'Royal Doulton',
                'model' => 'Bunnykins',
                'item_type' => 'vase',
            ],
        ]);
        $lead = Lead::create([
            'conversation_id' => $conversation->id,
            'client_id' => $client->id,
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'email_hash' => hash('sha256', 'jane@example.com'),
            'phone_raw' => '+447700900123',
            'phone_normalized' => '+447700900123',
            'phone_hash' => hash('sha256', '+447700900123'),
            'status' => 'REQUESTED',
        ]);

        $response = $this->postJson('/api/widget/appraisal/confirm', [
            'client_id' => $client->id,
            'session_token' => $token,
            'action_id' => (string) Str::uuid(),
            'confirm' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('blocked', true)
            ->assertJsonPath('reason_code', 'VALUATION_CONTACT_REQUIRED')
            ->assertJsonPath('pending_intent', 'valuation')
            ->assertJsonPath('lead_id', $lead->id)
            ->assertJsonPath('valuation_contact_prefill.email', 'jane@example.com')
            ->assertJsonPath('valuation_contact_prefill.name', 'Jane Doe')
            ->assertJsonPath('valuation_contact_prefill.phone', '+447700900123');

        $conversation->refresh();
        $this->assertSame(ConversationState::VALUATION_CONTACT_CAPTURE, $conversation->state);
        $this->assertTrue(
            ConversationEvent::query()
                ->where('conversation_id', $conversation->id)
                ->where('type', ConversationEventType::VALUATION_CONTACT_REQUESTED)
                ->where('payload->pending_intent', 'valuation')
                ->where('payload->lead_id', $lead->id)
                ->where('payload->valuation_contact_prefill->email', 'jane@example.com')
                ->exists()
        );
        $this->assertFalse(
            ConversationEvent::query()
                ->where('conversation_id', $conversation->id)
                ->where('type', ConversationEventType::VALUATION_REQUESTED)
                ->exists()
        );
    }

    public function test_cancel_from_wrong_state_returns_error(): void
    {
        $client = $this->makeClient();
        [$conversation, $token] = $this->makeConversation($client, [
            'state' => ConversationState::VALUATION_RUNNING, // Not in APPRAISAL_CONFIRM
        ]);

        $response = $this->postJson('/api/widget/appraisal/confirm', [
            'client_id' => $client->id,
            'session_token' => $token,
            'action_id' => (string) Str::uuid(),
            'confirm' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('blocked', false);

        // No events should be created
        $this->assertSame(0, ConversationEvent::where('conversation_id', $conversation->id)->count());
    }
}
