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

class LeadIdentityConfirmTest extends TestCase
{
    use RefreshDatabase, InteractsWithConversations;

    public function test_yes_reuses_identity_creates_new_lead_and_sends_success_messages(): void
    {
        $client = $this->makeClient();
        [$conversation, $token] = $this->makeConversation($client, [
            'state' => ConversationState::LEAD_IDENTITY_CONFIRM,
            'lead_identity_candidate' => [
                'previous_lead_id' => '019c63da-6b58-7225-8623-471b9ab8ddc5',
                'name' => 'Jane Doe',
                'email' => 'jane@example.com',
                'phone_raw' => '+1 (202) 555-0110',
                'phone_normalized' => '+12025550110',
            ],
        ]);

        $response = $this->postJson('/api/widget/lead/confirm-identity', [
            'client_id' => $client->id,
            'session_token' => $token,
            'action_id' => (string) Str::uuid(),
            'use_existing' => true,
        ]);

        $response->assertOk()->assertJson(['ok' => true]);

        $this->assertTrue(ConversationEvent::where('conversation_id', $conversation->id)
            ->where('type', ConversationEventType::LEAD_IDENTITY_DECISION_RECORDED)
            ->where('payload->use_existing', true)
            ->exists());
        $this->assertTrue(ConversationEvent::where('conversation_id', $conversation->id)
            ->where('type', ConversationEventType::LEAD_REQUESTED)
            ->where('payload->source', 'reused_previous_lead')
            ->exists());

        $this->assertSame(1, Lead::where('conversation_id', $conversation->id)->count());
        $this->assertTrue(ConversationEvent::where('conversation_id', $conversation->id)
            ->where('type', ConversationEventType::ASSISTANT_MESSAGE_CREATED)
            ->where('payload->content', 'Thanks. Your lead request has been submitted. Our team will contact you soon.')
            ->exists());
        $this->assertTrue(ConversationEvent::where('conversation_id', $conversation->id)
            ->where('type', ConversationEventType::ASSISTANT_MESSAGE_CREATED)
            ->where('payload->content', 'Do you have any other questions?')
            ->exists());

        $conversation->refresh();
        $this->assertSame(ConversationState::CHAT, $conversation->state);
        $this->assertNull($conversation->lead_identity_candidate);
    }

    public function test_no_starts_fresh_lead_intake_and_keeps_old_leads(): void
    {
        $client = $this->makeClient();
        [$conversation, $token] = $this->makeConversation($client, [
            'state' => ConversationState::LEAD_IDENTITY_CONFIRM,
            'lead_identity_candidate' => [
                'previous_lead_id' => '019c63da-6b58-7225-8623-471b9ab8ddc5',
                'name' => 'Jane Doe',
                'email' => 'jane@example.com',
                'phone_raw' => '+1 (202) 555-0110',
                'phone_normalized' => '+12025550110',
            ],
        ]);

        Lead::create([
            'conversation_id' => $conversation->id,
            'client_id' => $client->id,
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'phone_raw' => '+1 (202) 555-0110',
            'phone_normalized' => '+12025550110',
            'status' => 'REQUESTED',
        ]);

        $response = $this->postJson('/api/widget/lead/confirm-identity', [
            'client_id' => $client->id,
            'session_token' => $token,
            'action_id' => (string) Str::uuid(),
            'use_existing' => false,
        ]);

        $response->assertOk()->assertJson(['ok' => true]);

        $this->assertTrue(ConversationEvent::where('conversation_id', $conversation->id)
            ->where('type', ConversationEventType::LEAD_IDENTITY_DECISION_RECORDED)
            ->where('payload->use_existing', false)
            ->exists());
        $this->assertTrue(ConversationEvent::where('conversation_id', $conversation->id)
            ->where('type', ConversationEventType::LEAD_STARTED)
            ->exists());
        $this->assertTrue(ConversationEvent::where('conversation_id', $conversation->id)
            ->where('type', ConversationEventType::LEAD_QUESTION_ASKED)
            ->where('payload->question_key', 'name')
            ->exists());
        $this->assertSame(1, Lead::where('conversation_id', $conversation->id)->count());

        $conversation->refresh();
        $this->assertSame(ConversationState::LEAD_INTAKE, $conversation->state);
        $this->assertNull($conversation->lead_identity_candidate);
    }

    public function test_identity_confirm_is_idempotent_by_action_id(): void
    {
        $client = $this->makeClient();
        [$conversation, $token] = $this->makeConversation($client, [
            'state' => ConversationState::LEAD_IDENTITY_CONFIRM,
            'lead_identity_candidate' => [
                'name' => 'Jane Doe',
                'email' => 'jane@example.com',
                'phone_raw' => '+1 (202) 555-0110',
                'phone_normalized' => '+12025550110',
            ],
        ]);

        $actionId = (string) Str::uuid();
        $payload = [
            'client_id' => $client->id,
            'session_token' => $token,
            'action_id' => $actionId,
            'use_existing' => true,
        ];

        $response1 = $this->postJson('/api/widget/lead/confirm-identity', $payload)->assertOk();
        $lastEventId1 = $response1->json('last_event_id');

        $response2 = $this->postJson('/api/widget/lead/confirm-identity', $payload)->assertOk();
        $lastEventId2 = $response2->json('last_event_id');

        $this->assertSame($lastEventId1, $lastEventId2);
        $this->assertSame(1, ConversationEvent::where('conversation_id', $conversation->id)
            ->where('type', ConversationEventType::LEAD_IDENTITY_DECISION_RECORDED)
            ->where('idempotency_key', $actionId)
            ->count());
        $this->assertSame(1, Lead::where('conversation_id', $conversation->id)->count());
    }

    public function test_identity_confirm_rejects_invalid_session_token(): void
    {
        $client = $this->makeClient();

        $this->postJson('/api/widget/lead/confirm-identity', [
            'client_id' => $client->id,
            'session_token' => str_repeat('x', 64),
            'action_id' => (string) Str::uuid(),
            'use_existing' => true,
        ])->assertUnauthorized()
            ->assertJson(['error' => 'Invalid session token']);
    }

    public function test_identity_confirm_requires_lead_identity_confirm_state(): void
    {
        $client = $this->makeClient();
        [$conversation, $token] = $this->makeConversation($client, [
            'state' => ConversationState::CHAT,
        ]);

        $this->postJson('/api/widget/lead/confirm-identity', [
            'client_id' => $client->id,
            'session_token' => $token,
            'action_id' => (string) Str::uuid(),
            'use_existing' => true,
        ])->assertStatus(409)
            ->assertJson(['error' => 'Conversation is not awaiting lead identity confirmation']);
    }

    public function test_identity_confirm_requires_valid_candidate_payload(): void
    {
        $client = $this->makeClient();
        [$conversation, $token] = $this->makeConversation($client, [
            'state' => ConversationState::LEAD_IDENTITY_CONFIRM,
            'lead_identity_candidate' => null,
        ]);

        $this->postJson('/api/widget/lead/confirm-identity', [
            'client_id' => $client->id,
            'session_token' => $token,
            'action_id' => (string) Str::uuid(),
            'use_existing' => true,
        ])->assertStatus(409)
            ->assertJson(['error' => 'No valid lead identity candidate found']);
    }
}
