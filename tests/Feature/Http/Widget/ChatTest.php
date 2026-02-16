<?php

namespace Tests\Feature\Http\Widget;

use App\Enums\ConversationState;
use App\Enums\ConversationEventType;
use App\Models\ConversationEvent;
use App\Models\ConversationMessage;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithConversations;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase, InteractsWithConversations;

    public function test_sends_message_and_creates_events(): void
    {
        $client = $this->makeClient();
        [$conversation, $rawToken] = $this->makeConversation($client);

        $messageId = (string) Str::uuid();

        $response = $this->postJson('/api/widget/chat', [
            'client_id' => $client->id,
            'session_token' => $rawToken,
            'message_id' => $messageId,
            'text' => 'Hello there',
        ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'conversation_id' => $conversation->id,
            ])
            ->assertJsonStructure([
                'ok',
                'conversation_id',
                'correlation_id',
                'last_event_id',
            ]);

        // Should create 2 events (user + assistant) - ordered by event_id
        $events = ConversationEvent::where('conversation_id', $conversation->id)
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $events);

        // Should create 2 projected messages - ordered by event_id
        $messages = ConversationMessage::where('conversation_id', $conversation->id)
            ->orderBy('event_id')
            ->get();
        $this->assertCount(2, $messages);

        // First message should be user, second should be assistant
        $this->assertSame('user', $messages[0]->role);
        $this->assertSame('Hello there', $messages[0]->content);
        $this->assertSame('assistant', $messages[1]->role);
    }

    public function test_chat_triggers_appraisal_flow_when_intent_detected(): void
    {
        $client = $this->makeClient();
        $this->makeAppraisalQuestion($client, ['key' => 'maker', 'order_index' => 1]);
        [$conversation, $rawToken] = $this->makeConversation($client);

        $response = $this->postJson('/api/widget/chat', [
            'client_id' => $client->id,
            'session_token' => $rawToken,
            'message_id' => (string) \Illuminate\Support\Str::uuid(),
            'text' => 'How much is this worth?',
        ]);

        $response->assertOk();

        $this->assertTrue(ConversationEvent::where('conversation_id', $conversation->id)
            ->where('type', ConversationEventType::APPRAISAL_QUESTION_ASKED)
            ->exists());
    }

    public function test_chat_triggers_lead_intake_from_valuation_ready(): void
    {
        $client = $this->makeClient();
        [$conversation, $rawToken] = $this->makeConversation($client, [
            'state' => ConversationState::VALUATION_READY,
        ]);

        $response = $this->postJson('/api/widget/chat', [
            'client_id' => $client->id,
            'session_token' => $rawToken,
            'message_id' => (string) Str::uuid(),
            'text' => 'Please request an expert manual review for this item.',
        ]);

        $response->assertOk();

        $this->assertTrue(ConversationEvent::where('conversation_id', $conversation->id)
            ->where('type', ConversationEventType::LEAD_STARTED)
            ->exists());
        $this->assertTrue(ConversationEvent::where('conversation_id', $conversation->id)
            ->where('type', ConversationEventType::LEAD_QUESTION_ASKED)
            ->where('payload->question_key', 'name')
            ->exists());
    }

    public function test_chat_triggers_lead_intake_from_loose_phrase(): void
    {
        $client = $this->makeClient();
        [$conversation, $rawToken] = $this->makeConversation($client, [
            'state' => ConversationState::VALUATION_READY,
        ]);

        $response = $this->postJson('/api/widget/chat', [
            'client_id' => $client->id,
            'session_token' => $rawToken,
            'message_id' => (string) Str::uuid(),
            'text' => 'Can someone from your team follow up to review this item?',
        ]);

        $response->assertOk();

        $this->assertTrue(ConversationEvent::where('conversation_id', $conversation->id)
            ->where('type', ConversationEventType::LEAD_STARTED)
            ->exists());
    }

    public function test_chat_requests_lead_identity_confirmation_when_previous_lead_exists(): void
    {
        $client = $this->makeClient();
        [$conversation, $rawToken] = $this->makeConversation($client, [
            'state' => ConversationState::VALUATION_READY,
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

        $response = $this->postJson('/api/widget/chat', [
            'client_id' => $client->id,
            'session_token' => $rawToken,
            'message_id' => (string) Str::uuid(),
            'text' => 'Please request an expert manual review for this item.',
        ]);

        $response->assertOk();

        $this->assertTrue(ConversationEvent::where('conversation_id', $conversation->id)
            ->where('type', ConversationEventType::LEAD_IDENTITY_CONFIRMATION_REQUESTED)
            ->exists());
        $this->assertFalse(ConversationEvent::where('conversation_id', $conversation->id)
            ->where('type', ConversationEventType::LEAD_STARTED)
            ->exists());

        $conversation->refresh();
        $this->assertSame(ConversationState::LEAD_IDENTITY_CONFIRM, $conversation->state);
    }

    public function test_chat_completes_lead_intake_and_creates_request(): void
    {
        $client = $this->makeClient();
        [$conversation, $rawToken] = $this->makeConversation($client, [
            'state' => ConversationState::LEAD_INTAKE,
            'lead_current_key' => 'phone',
            'lead_answers' => [
                'name' => 'Jane Doe',
                'email' => 'jane@example.com',
            ],
        ]);

        $response = $this->postJson('/api/widget/chat', [
            'client_id' => $client->id,
            'session_token' => $rawToken,
            'message_id' => (string) Str::uuid(),
            'text' => '+1 (202) 555-0110',
        ]);

        $response->assertOk();

        $this->assertTrue(ConversationEvent::where('conversation_id', $conversation->id)
            ->where('type', ConversationEventType::LEAD_REQUESTED)
            ->exists());

        $this->assertSame(
            1,
            Lead::where('conversation_id', $conversation->id)->count()
        );

        $this->assertTrue(ConversationEvent::where('conversation_id', $conversation->id)
            ->where('type', ConversationEventType::ASSISTANT_MESSAGE_CREATED)
            ->where('payload->content', 'Do you have any other questions?')
            ->exists());

        $conversation->refresh();
        $this->assertSame(ConversationState::CHAT, $conversation->state);
    }

    public function test_chat_reprompts_for_invalid_lead_email(): void
    {
        $client = $this->makeClient();
        [$conversation, $rawToken] = $this->makeConversation($client, [
            'state' => ConversationState::LEAD_INTAKE,
            'lead_current_key' => 'email',
            'lead_answers' => [
                'name' => 'Jane Doe',
            ],
        ]);

        $response = $this->postJson('/api/widget/chat', [
            'client_id' => $client->id,
            'session_token' => $rawToken,
            'message_id' => (string) Str::uuid(),
            'text' => 'not-an-email',
        ]);

        $response->assertOk();

        $this->assertFalse(ConversationEvent::where('conversation_id', $conversation->id)
            ->where('type', ConversationEventType::LEAD_ANSWER_RECORDED)
            ->exists());
        $this->assertFalse(Lead::where('conversation_id', $conversation->id)->exists());
    }

    public function test_chat_reprompts_for_invalid_lead_phone(): void
    {
        $client = $this->makeClient();
        [$conversation, $rawToken] = $this->makeConversation($client, [
            'state' => ConversationState::LEAD_INTAKE,
            'lead_current_key' => 'phone',
            'lead_answers' => [
                'name' => 'Jane Doe',
                'email' => 'jane@example.com',
            ],
        ]);

        $response = $this->postJson('/api/widget/chat', [
            'client_id' => $client->id,
            'session_token' => $rawToken,
            'message_id' => (string) Str::uuid(),
            'text' => '12',
        ]);

        $response->assertOk();

        $this->assertFalse(ConversationEvent::where('conversation_id', $conversation->id)
            ->where('type', ConversationEventType::LEAD_REQUESTED)
            ->exists());
        $this->assertFalse(Lead::where('conversation_id', $conversation->id)->exists());
    }

    public function test_retry_with_same_message_id_does_not_duplicate(): void
    {
        $client = $this->makeClient();
        [$conversation, $rawToken] = $this->makeConversation($client);

        $messageId = (string) Str::uuid();
        $payload = [
            'client_id' => $client->id,
            'session_token' => $rawToken,
            'message_id' => $messageId,
            'text' => 'Hello',
        ];

        // First request
        $response1 = $this->postJson('/api/widget/chat', $payload);
        $response1->assertOk();
        $firstLastEventId = $response1->json('last_event_id');

        // Second request (retry) with same message_id
        $response2 = $this->postJson('/api/widget/chat', $payload);
        $response2->assertOk();
        $secondLastEventId = $response2->json('last_event_id');

        // Retry should return same last_event_id (no new events written)
        $this->assertSame($firstLastEventId, $secondLastEventId);

        // Should still only have 2 events (not 4)
        $eventCount = ConversationEvent::where('conversation_id', $conversation->id)->count();
        $this->assertSame(2, $eventCount);

        // Should still only have 2 messages (not 4)
        $messageCount = ConversationMessage::where('conversation_id', $conversation->id)->count();
        $this->assertSame(2, $messageCount);

        // Explicitly verify only 1 user and 1 assistant message
        $userCount = ConversationMessage::where('conversation_id', $conversation->id)
            ->where('role', 'user')->count();
        $assistantCount = ConversationMessage::where('conversation_id', $conversation->id)
            ->where('role', 'assistant')->count();

        $this->assertSame(1, $userCount);
        $this->assertSame(1, $assistantCount);
    }

    public function test_returns_401_for_invalid_session_token(): void
    {
        $client = $this->makeClient();

        $response = $this->postJson('/api/widget/chat', [
            'client_id' => $client->id,
            'session_token' => str_repeat('x', 64),
            'message_id' => (string) Str::uuid(),
            'text' => 'Hello',
        ]);

        $response->assertUnauthorized()
            ->assertJson(['error' => 'Invalid session token']);
    }

    public function test_returns_422_for_invalid_client_id(): void
    {
        // Create a valid client and token to isolate the client_id validation
        $validClient = $this->makeClient();
        [$conversation, $validToken] = $this->makeConversation($validClient);

        // Use valid token but non-existent client_id
        $response = $this->postJson('/api/widget/chat', [
            'client_id' => '00000000-0000-0000-0000-000000000000',
            'session_token' => $validToken,
            'message_id' => (string) Str::uuid(),
            'text' => 'Hello',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['client_id']);
    }

    public function test_token_for_client_a_does_not_work_for_client_b(): void
    {
        $clientA = $this->makeClient(['name' => 'Client A']);
        $clientB = $this->makeClient(['name' => 'Client B']);

        [$conversationA, $tokenA] = $this->makeConversation($clientA);

        // Try to use Client A's token with Client B
        $response = $this->postJson('/api/widget/chat', [
            'client_id' => $clientB->id,
            'session_token' => $tokenA,
            'message_id' => (string) Str::uuid(),
            'text' => 'Hello',
        ]);

        // Should fail - token doesn't match client
        $response->assertUnauthorized();

        // No events should be created for either client
        $this->assertSame(0, ConversationEvent::where('client_id', $clientA->id)->count());
        $this->assertSame(0, ConversationEvent::where('client_id', $clientB->id)->count());

        // No messages should be created for either client
        $this->assertSame(0, ConversationMessage::where('client_id', $clientA->id)->count());
        $this->assertSame(0, ConversationMessage::where('client_id', $clientB->id)->count());
    }

    public function test_validates_text_length(): void
    {
        $client = $this->makeClient();
        [$conversation, $rawToken] = $this->makeConversation($client);

        // Empty text
        $response = $this->postJson('/api/widget/chat', [
            'client_id' => $client->id,
            'session_token' => $rawToken,
            'message_id' => (string) Str::uuid(),
            'text' => '',
        ]);
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['text']);

        // Text too long (> 2000 chars)
        $response = $this->postJson('/api/widget/chat', [
            'client_id' => $client->id,
            'session_token' => $rawToken,
            'message_id' => (string) Str::uuid(),
            'text' => str_repeat('a', 2001),
        ]);
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['text']);
    }

    public function test_trims_whitespace_from_text(): void
    {
        $client = $this->makeClient();
        [$conversation, $rawToken] = $this->makeConversation($client);

        $response = $this->postJson('/api/widget/chat', [
            'client_id' => $client->id,
            'session_token' => $rawToken,
            'message_id' => (string) Str::uuid(),
            'text' => '   Hello World   ',
        ]);

        $response->assertOk();

        // Message should be trimmed
        $userMessage = ConversationMessage::where('conversation_id', $conversation->id)
            ->where('role', 'user')
            ->first();

        $this->assertSame('Hello World', $userMessage->content);
    }

    public function test_correlation_id_is_valid_uuid_and_links_events(): void
    {
        $client = $this->makeClient();
        [$conversation, $rawToken] = $this->makeConversation($client);

        $response = $this->postJson('/api/widget/chat', [
            'client_id' => $client->id,
            'session_token' => $rawToken,
            'message_id' => (string) Str::uuid(),
            'text' => 'Hello',
        ]);

        $response->assertOk();

        $correlationId = $response->json('correlation_id');

        // correlation_id must be a valid UUID
        $this->assertTrue(Str::isUuid($correlationId));

        // Both events should share the same correlation_id - ordered by id
        $events = ConversationEvent::where('conversation_id', $conversation->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $events);
        $this->assertTrue($events->every(fn ($e) => $e->correlation_id === $correlationId));
    }

    public function test_last_event_id_increments_correctly(): void
    {
        $client = $this->makeClient();
        [$conversation, $rawToken] = $this->makeConversation($client);

        // Initial state (default is 0, but may be null before first event)
        $this->assertTrue(in_array($conversation->last_event_id, [0, null], true));

        $response = $this->postJson('/api/widget/chat', [
            'client_id' => $client->id,
            'session_token' => $rawToken,
            'message_id' => (string) Str::uuid(),
            'text' => 'Hello',
        ]);

        $response->assertOk();

        // last_event_id should equal the latest event's id
        $latestEventId = ConversationEvent::where('conversation_id', $conversation->id)
            ->orderByDesc('id')
            ->value('id');

        $this->assertSame($latestEventId, $response->json('last_event_id'));

        // Conversation model should also be updated
        $conversation->refresh();
        $this->assertSame($latestEventId, $conversation->last_event_id);
    }

    public function test_second_message_increments_event_id_further(): void
    {
        $client = $this->makeClient();
        [$conversation, $rawToken] = $this->makeConversation($client);

        // First message
        $response1 = $this->postJson('/api/widget/chat', [
            'client_id' => $client->id,
            'session_token' => $rawToken,
            'message_id' => (string) Str::uuid(),
            'text' => 'First message',
        ]);
        $response1->assertOk();
        $firstLastEventId = $response1->json('last_event_id');

        // Second message (different message_id)
        $response2 = $this->postJson('/api/widget/chat', [
            'client_id' => $client->id,
            'session_token' => $rawToken,
            'message_id' => (string) Str::uuid(),
            'text' => 'Second message',
        ]);
        $response2->assertOk();
        $secondLastEventId = $response2->json('last_event_id');

        // Second last_event_id should be greater than first
        $this->assertGreaterThan($firstLastEventId, $secondLastEventId);

        // Should have 4 events total (2 per message)
        $eventCount = ConversationEvent::where('conversation_id', $conversation->id)->count();
        $this->assertSame(4, $eventCount);
    }
}
