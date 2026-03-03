<?php

namespace Tests\Feature\Http\Widget;

use App\Enums\ConversationEventType;
use App\Enums\ConversationState;
use App\Models\ConversationEvent;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithConversations;
use Tests\TestCase;

class ValuationContactCaptureTest extends TestCase
{
    use RefreshDatabase, InteractsWithConversations;

    public function test_capture_contact_creates_lead_and_emits_event(): void
    {
        $client = $this->makeClient();
        [$conversation, $token] = $this->makeConversation($client, [
            'state' => ConversationState::VALUATION_CONTACT_CAPTURE,
        ]);

        $response = $this->withHeaders([
            'X-Client-Id' => (string) $client->id,
            'X-Session-Token' => $token,
        ])->postJson('/api/widget/valuation/contact', [
            'action_id' => (string) Str::uuid(),
            'email' => 'Lead@Example.com',
            'name' => 'Lead User',
            'phone' => '+447700900123',
        ])->assertOk()->json();

        $this->assertTrue((bool) ($response['ok'] ?? false));
        $this->assertIsString($response['lead_id']);

        $lead = Lead::query()->find($response['lead_id']);
        $this->assertNotNull($lead);
        $this->assertSame((string) $conversation->id, (string) $lead->conversation_id);
        $this->assertSame('lead@example.com', $lead->email);

        $conversation->refresh();
        $this->assertSame(ConversationState::APPRAISAL_CONFIRM, $conversation->state);
        $this->assertSame((string) $lead->id, (string) $conversation->valuation_contact_lead_id);

        $this->assertTrue(
            ConversationEvent::query()
                ->where('conversation_id', $conversation->id)
                ->where('type', ConversationEventType::VALUATION_CONTACT_CAPTURED)
                ->where('payload->lead_id', (string) $lead->id)
                ->exists()
        );
    }

    public function test_capture_contact_is_idempotent_by_action_id(): void
    {
        $client = $this->makeClient();
        [$conversation, $token] = $this->makeConversation($client, [
            'state' => ConversationState::VALUATION_CONTACT_CAPTURE,
        ]);

        $actionId = (string) Str::uuid();
        $headers = [
            'X-Client-Id' => (string) $client->id,
            'X-Session-Token' => $token,
        ];
        $payload = [
            'action_id' => $actionId,
            'email' => 'lead@example.com',
            'name' => 'Lead User',
        ];

        $first = $this->withHeaders($headers)->postJson('/api/widget/valuation/contact', $payload)->assertOk()->json();
        $second = $this->withHeaders($headers)->postJson('/api/widget/valuation/contact', $payload)->assertOk()->json();

        $this->assertSame($first['lead_id'], $second['lead_id']);
        $this->assertSame(
            1,
            Lead::query()
                ->where('conversation_id', $conversation->id)
                ->where('lead_capture_action_id', $actionId)
                ->count()
        );
    }

    public function test_capture_contact_rejects_invalid_state(): void
    {
        $client = $this->makeClient();
        [, $token] = $this->makeConversation($client, [
            'state' => ConversationState::APPRAISAL_CONFIRM,
        ]);

        $this->withHeaders([
            'X-Client-Id' => (string) $client->id,
            'X-Session-Token' => $token,
        ])->postJson('/api/widget/valuation/contact', [
            'action_id' => (string) Str::uuid(),
            'email' => 'lead@example.com',
        ])->assertStatus(409)
            ->assertJsonPath('error', 'CONFLICT')
            ->assertJsonPath('reason_code', 'INVALID_STATE_FOR_CONTACT_CAPTURE');
    }

    public function test_capture_contact_is_noop_when_lead_already_linked(): void
    {
        $client = $this->makeClient();
        [$conversation, $token] = $this->makeConversation($client, [
            'state' => ConversationState::APPRAISAL_CONFIRM,
        ]);

        $lead = Lead::create([
            'conversation_id' => $conversation->id,
            'client_id' => $client->id,
            'name' => 'Lead User',
            'email' => 'lead@example.com',
            'email_hash' => hash('sha256', 'lead@example.com'),
            'phone_raw' => '+447700900123',
            'phone_normalized' => '+447700900123',
            'phone_hash' => hash('sha256', '+447700900123'),
            'status' => 'REQUESTED',
        ]);
        $conversation->update(['valuation_contact_lead_id' => $lead->id]);

        $response = $this->withHeaders([
            'X-Client-Id' => (string) $client->id,
            'X-Session-Token' => $token,
        ])->postJson('/api/widget/valuation/contact', [
            'action_id' => (string) Str::uuid(),
            'email' => 'another@example.com',
        ])->assertOk()->json();

        $this->assertSame((string) $lead->id, (string) ($response['lead_id'] ?? null));
        $this->assertSame(
            1,
            Lead::query()->where('conversation_id', $conversation->id)->count()
        );
        $this->assertFalse(
            ConversationEvent::query()
                ->where('conversation_id', $conversation->id)
                ->where('type', ConversationEventType::VALUATION_CONTACT_CAPTURED)
                ->exists()
        );
    }

    public function test_capture_contact_is_tenant_scoped(): void
    {
        $clientA = $this->makeClient(['name' => 'A']);
        $clientB = $this->makeClient(['name' => 'B']);

        [$conversationA, $tokenA] = $this->makeConversation($clientA, [
            'state' => ConversationState::VALUATION_CONTACT_CAPTURE,
        ]);

        $this->withHeaders([
            'X-Client-Id' => (string) $clientB->id,
            'X-Session-Token' => $tokenA,
        ])->postJson('/api/widget/valuation/contact', [
            'action_id' => (string) Str::uuid(),
            'email' => 'lead@example.com',
        ])->assertUnauthorized();

        $this->assertSame(0, Lead::query()->where('conversation_id', $conversationA->id)->count());
    }

    public function test_capture_contact_auto_submits_expert_review_when_pending_intent_is_expert_review(): void
    {
        $client = $this->makeClient();
        [$conversation, $token] = $this->makeConversation($client, [
            'state' => ConversationState::VALUATION_CONTACT_CAPTURE,
            'context' => ['pending_intent' => 'expert_review'],
        ]);

        $response = $this->withHeaders([
            'X-Client-Id' => (string) $client->id,
            'X-Session-Token' => $token,
        ])->postJson('/api/widget/valuation/contact', [
            'action_id' => (string) Str::uuid(),
            'email' => 'lead@example.com',
            'name' => 'Lead User',
            'phone' => '+447700900123',
        ])->assertOk()->json();

        $leadId = (string) ($response['lead_id'] ?? '');
        $this->assertNotSame('', $leadId);
        $this->assertTrue(
            ConversationEvent::query()
                ->where('conversation_id', $conversation->id)
                ->where('type', ConversationEventType::LEAD_REQUESTED)
                ->where('payload->lead_id', $leadId)
                ->exists()
        );
        $this->assertTrue(
            ConversationEvent::query()
                ->where('conversation_id', $conversation->id)
                ->where('type', ConversationEventType::ASSISTANT_MESSAGE_CREATED)
                ->where('payload->content', 'Thanks. Your lead request has been submitted. Our team will contact you soon.')
                ->exists()
        );

        $conversation->refresh();
        $this->assertSame(ConversationState::CHAT, $conversation->state);
        $this->assertSame($leadId, (string) $conversation->valuation_contact_lead_id);
    }
}
