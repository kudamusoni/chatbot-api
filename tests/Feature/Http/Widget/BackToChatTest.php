<?php

namespace Tests\Feature\Http\Widget;

use App\Enums\ConversationEventType;
use App\Enums\ConversationState;
use App\Models\ConversationEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithConversations;
use Tests\TestCase;

class BackToChatTest extends TestCase
{
    use RefreshDatabase, InteractsWithConversations;

    public function test_back_to_chat_moves_state_and_emits_follow_up_message(): void
    {
        $client = $this->makeClient();
        [$conversation, $token] = $this->makeConversation($client, [
            'state' => ConversationState::APPRAISAL_CONFIRM,
            'appraisal_answers' => ['maker' => 'Rolex'],
            'appraisal_current_key' => 'age',
            'appraisal_snapshot' => ['maker' => 'Rolex', 'age' => '1970'],
            'lead_answers' => ['name' => 'Jane'],
            'lead_current_key' => 'email',
            'lead_identity_candidate' => [
                'name' => 'Jane',
                'email' => 'jane@example.com',
                'phone_raw' => '+1 202 555 0110',
                'phone_normalized' => '+12025550110',
            ],
        ]);

        $response = $this->postJson('/api/widget/back-to-chat', [
            'client_id' => $client->id,
            'session_token' => $token,
            'action_id' => (string) Str::uuid(),
        ]);

        $response->assertOk()->assertJson(['ok' => true]);

        $conversation->refresh();
        $this->assertSame(ConversationState::CHAT, $conversation->state);
        $this->assertNull($conversation->appraisal_answers);
        $this->assertNull($conversation->appraisal_current_key);
        $this->assertNull($conversation->appraisal_snapshot);
        $this->assertNull($conversation->lead_answers);
        $this->assertNull($conversation->lead_current_key);
        $this->assertNull($conversation->lead_identity_candidate);

        $this->assertTrue(
            ConversationEvent::where('conversation_id', $conversation->id)
                ->where('type', ConversationEventType::ASSISTANT_MESSAGE_CREATED)
                ->where('payload->content', 'Do you have any more questions?')
                ->exists()
        );
    }

    public function test_back_to_chat_is_idempotent_by_action_id(): void
    {
        $client = $this->makeClient();
        [$conversation, $token] = $this->makeConversation($client, [
            'state' => ConversationState::LEAD_INTAKE,
        ]);

        $actionId = (string) Str::uuid();
        $payload = [
            'client_id' => $client->id,
            'session_token' => $token,
            'action_id' => $actionId,
        ];

        $response1 = $this->postJson('/api/widget/back-to-chat', $payload)->assertOk();
        $lastEventId1 = $response1->json('last_event_id');

        $response2 = $this->postJson('/api/widget/back-to-chat', $payload)->assertOk();
        $lastEventId2 = $response2->json('last_event_id');

        $this->assertSame($lastEventId1, $lastEventId2);
        $this->assertSame(1, ConversationEvent::where('conversation_id', $conversation->id)
            ->where('type', ConversationEventType::ASSISTANT_MESSAGE_CREATED)
            ->where('idempotency_key', "back_to_chat:{$actionId}:assistant")
            ->count());
    }

    public function test_back_to_chat_rejects_invalid_session_token(): void
    {
        $client = $this->makeClient();

        $this->postJson('/api/widget/back-to-chat', [
            'client_id' => $client->id,
            'session_token' => str_repeat('x', 64),
            'action_id' => (string) Str::uuid(),
        ])->assertUnauthorized()
            ->assertJson(['error' => 'Invalid session token']);
    }
}
