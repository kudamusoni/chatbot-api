<?php

namespace Tests\Feature\Http\Widget;

use App\Enums\ConversationEventType;
use App\Enums\ConversationState;
use App\Enums\ValuationStatus;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\ConversationMessage;
use App\Models\Lead;
use App\Models\Valuation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithConversations;
use Tests\TestCase;

class ResetTest extends TestCase
{
    use RefreshDatabase, InteractsWithConversations;

    public function test_reset_clears_existing_chat_data_and_starts_with_intro_message_without_deleting_leads_or_valuations(): void
    {
        $client = $this->makeClient();
        [$conversation, $token] = $this->makeConversation($client, [
            'state' => ConversationState::LEAD_INTAKE,
            'lead_current_key' => 'phone',
            'lead_answers' => [
                'name' => 'Jane Doe',
                'email' => 'jane@example.com',
            ],
            'lead_identity_candidate' => [
                'name' => 'Jane Doe',
                'email' => 'jane@example.com',
                'phone_raw' => '+1 (202) 555-0110',
                'phone_normalized' => '+12025550110',
            ],
            'appraisal_answers' => ['maker' => 'Rolex'],
            'appraisal_snapshot' => ['maker' => 'Rolex', 'age' => '1970'],
        ]);

        $this->recordUserMessage($conversation, 'Old message 1');
        $this->recordAssistantMessage($conversation, 'Old response 1');

        Valuation::create([
            'conversation_id' => $conversation->id,
            'client_id' => $client->id,
            'status' => ValuationStatus::COMPLETED,
            'snapshot_hash' => 'hash-' . Str::random(8),
            'input_snapshot' => ['maker' => 'Rolex'],
            'result' => ['median' => 5000],
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

        $oldConversationId = $conversation->id;
        $actionId = (string) Str::uuid();

        $response = $this->postJson('/api/widget/reset', [
            'client_id' => $client->id,
            'session_token' => $token,
            'action_id' => $actionId,
        ]);

        $response->assertOk()->assertJson([
            'ok' => true,
            'session_token' => $token,
        ]);

        $newConversationId = $response->json('conversation_id');
        $this->assertSame($oldConversationId, $newConversationId);
        $this->assertDatabaseHas('conversations', ['id' => $oldConversationId]);

        $newConversation = Conversation::find($newConversationId);
        $this->assertNotNull($newConversation);
        $this->assertSame(ConversationState::CHAT, $newConversation->state);
        $this->assertNull($newConversation->lead_current_key);
        $this->assertNull($newConversation->lead_answers);
        $this->assertNull($newConversation->lead_identity_candidate);
        $this->assertNull($newConversation->appraisal_snapshot);

        $introEvent = ConversationEvent::where('conversation_id', $newConversationId)
            ->where('type', ConversationEventType::ASSISTANT_MESSAGE_CREATED)
            ->where('payload->content', 'Thank you for your message. How can I help you today?')
            ->first();

        $this->assertNotNull($introEvent);
        $turnCompleted = ConversationEvent::where('conversation_id', $newConversationId)
            ->where('type', ConversationEventType::TURN_COMPLETED)
            ->first();
        $this->assertNotNull($turnCompleted);
        $this->assertSame($newConversation->last_event_id, $turnCompleted->id);

        $this->assertSame(1, ConversationMessage::where('conversation_id', $newConversationId)->count());
        $this->assertSame(1, Valuation::where('conversation_id', $newConversationId)->count());
        $this->assertSame(1, Lead::where('conversation_id', $newConversationId)->count());
    }

    public function test_reset_is_idempotent_by_action_id(): void
    {
        $client = $this->makeClient();
        [$conversation, $token] = $this->makeConversation($client);

        $actionId = (string) Str::uuid();
        $payload = [
            'client_id' => $client->id,
            'session_token' => $token,
            'action_id' => $actionId,
        ];

        $response1 = $this->postJson('/api/widget/reset', $payload)->assertOk();
        $conversationId1 = $response1->json('conversation_id');

        $response2 = $this->postJson('/api/widget/reset', $payload)->assertOk();
        $conversationId2 = $response2->json('conversation_id');

        $this->assertSame($conversationId1, $conversationId2);

        $this->assertSame(1, ConversationEvent::where('conversation_id', $conversationId1)
            ->where('type', ConversationEventType::ASSISTANT_MESSAGE_CREATED)
            ->where('idempotency_key', "reset:{$actionId}:intro")
            ->count());
    }

    public function test_reset_rejects_invalid_session_token(): void
    {
        $client = $this->makeClient();

        $this->postJson('/api/widget/reset', [
            'client_id' => $client->id,
            'session_token' => str_repeat('x', 64),
            'action_id' => (string) Str::uuid(),
        ])->assertUnauthorized()
            ->assertJson(['error' => 'Invalid session token']);
    }
}
