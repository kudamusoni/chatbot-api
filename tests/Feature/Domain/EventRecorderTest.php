<?php

namespace Tests\Feature\Domain;

use App\Enums\ConversationEventType;
use App\Models\ConversationEvent;
use App\Models\ConversationMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithConversations;
use Tests\TestCase;

/**
 * Tests for event recording invariants and idempotency.
 * These form the reliability core of the system.
 */
class EventRecorderTest extends TestCase
{
    use RefreshDatabase, InteractsWithConversations;

    // =========================================================================
    // Event Recording Invariants
    // =========================================================================

    public function test_record_creates_event_with_correct_ids(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client);

        $result = $this->recordUserMessage($conversation, 'Hello world');

        $event = $result['event'];

        $this->assertTrue($result['created']);
        $this->assertEquals($conversation->id, $event->conversation_id);
        $this->assertEquals($client->id, $event->client_id);
        $this->assertEquals(ConversationEventType::USER_MESSAGE_CREATED, $event->type);
    }

    public function test_record_generates_correlation_id_if_not_provided(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client);

        $result = $this->recordUserMessage($conversation, 'Hello');

        $this->assertNotNull($result['event']->correlation_id);
        $this->assertTrue(\Ramsey\Uuid\Uuid::isValid($result['event']->correlation_id));
    }

    public function test_record_uses_provided_correlation_id(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client);

        $correlationId = (string) \Illuminate\Support\Str::uuid();
        $result = $this->recordUserMessage($conversation, 'Hello', null, $correlationId);

        $this->assertEquals($correlationId, $result['event']->correlation_id);
        $this->assertTrue(\Ramsey\Uuid\Uuid::isValid($result['event']->correlation_id));
    }

    public function test_record_rejects_invalid_correlation_id(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Correlation ID must be a valid UUID');

        $this->recordUserMessage($conversation, 'Hello', null, 'not-a-valid-uuid');
    }

    public function test_event_ids_are_monotonic(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client);

        $result1 = $this->recordUserMessage($conversation, 'First message');
        $result2 = $this->recordAssistantMessage($conversation, 'Second message');
        $result3 = $this->recordUserMessage($conversation, 'Third message');

        $this->assertGreaterThan($result1['event']->id, $result2['event']->id);
        $this->assertGreaterThan($result2['event']->id, $result3['event']->id);
    }

    public function test_event_ids_are_monotonic_across_conversations(): void
    {
        $client = $this->makeClient();
        [$conv1, ] = $this->makeConversation($client);
        [$conv2, ] = $this->makeConversation($client);

        $result1 = $this->recordUserMessage($conv1, 'Message in conv1');
        $result2 = $this->recordUserMessage($conv2, 'Message in conv2');
        $result3 = $this->recordUserMessage($conv1, 'Another in conv1');

        // IDs should be globally monotonic
        $this->assertGreaterThan($result1['event']->id, $result2['event']->id);
        $this->assertGreaterThan($result2['event']->id, $result3['event']->id);
    }

    public function test_payload_is_never_null(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client);

        $result = $this->recordUserMessage($conversation, 'Test message');

        $this->assertNotNull($result['event']->payload);
        $this->assertIsArray($result['event']->payload);
        $this->assertArrayHasKey('content', $result['event']->payload);
    }

    public function test_payload_stores_correct_content(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client);

        $content = 'This is my test message with special chars: <>&"\'';
        $result = $this->recordUserMessage($conversation, $content);

        $this->assertEquals($content, $result['event']->payload['content']);
    }

    // =========================================================================
    // Idempotency Invariants
    // =========================================================================

    public function test_idempotent_recording_creates_single_event(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client);

        $idempotencyKey = 'user-message-abc123';

        // Record twice with same idempotency key
        $result1 = $this->recordUserMessage($conversation, 'Hello', $idempotencyKey);
        $result2 = $this->recordUserMessage($conversation, 'Hello', $idempotencyKey);

        // First should be created, second should not
        $this->assertTrue($result1['created']);
        $this->assertFalse($result2['created']);

        // Both should return same event
        $this->assertEquals($result1['event']->id, $result2['event']->id);

        // Only one event should exist
        $count = ConversationEvent::where('conversation_id', $conversation->id)->count();
        $this->assertEquals(1, $count);
    }

    public function test_idempotent_recording_returns_existing_event(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client);

        $idempotencyKey = 'user-message-xyz789';

        $result1 = $this->recordUserMessage($conversation, 'Original message', $idempotencyKey);
        $result2 = $this->recordUserMessage($conversation, 'Different content', $idempotencyKey);

        // Should return the original event with original content
        $this->assertEquals('Original message', $result2['event']->payload['content']);
    }

    public function test_different_idempotency_keys_create_different_events(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client);

        $result1 = $this->recordUserMessage($conversation, 'Message 1', 'key-1');
        $result2 = $this->recordUserMessage($conversation, 'Message 2', 'key-2');

        $this->assertTrue($result1['created']);
        $this->assertTrue($result2['created']);
        $this->assertNotEquals($result1['event']->id, $result2['event']->id);
    }

    public function test_same_idempotency_key_different_conversations_creates_both(): void
    {
        $client = $this->makeClient();
        [$conv1, ] = $this->makeConversation($client);
        [$conv2, ] = $this->makeConversation($client);

        $idempotencyKey = 'same-key';

        $result1 = $this->recordUserMessage($conv1, 'Message in conv1', $idempotencyKey);
        $result2 = $this->recordUserMessage($conv2, 'Message in conv2', $idempotencyKey);

        // Both should be created (idempotency is per-conversation)
        $this->assertTrue($result1['created']);
        $this->assertTrue($result2['created']);
        $this->assertNotEquals($result1['event']->id, $result2['event']->id);
    }

    public function test_null_idempotency_key_allows_duplicates(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client);

        // Record same content twice without idempotency key
        $result1 = $this->recordUserMessage($conversation, 'Same message');
        $result2 = $this->recordUserMessage($conversation, 'Same message');

        // Both should be created
        $this->assertTrue($result1['created']);
        $this->assertTrue($result2['created']);
        $this->assertNotEquals($result1['event']->id, $result2['event']->id);

        // Two events should exist
        $count = ConversationEvent::where('conversation_id', $conversation->id)->count();
        $this->assertEquals(2, $count);
    }

    public function test_idempotent_recording_does_not_duplicate_projections(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client);

        $idempotencyKey = 'user-message-no-dup';

        // Record twice
        $this->recordUserMessage($conversation, 'Hello', $idempotencyKey);
        $this->recordUserMessage($conversation, 'Hello', $idempotencyKey);

        // Only one message projection should exist
        $messageCount = ConversationMessage::where('conversation_id', $conversation->id)->count();
        $this->assertEquals(1, $messageCount);
    }

    // =========================================================================
    // Event Type Tests
    // =========================================================================

    public function test_user_message_event_has_correct_type(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client);

        $result = $this->recordUserMessage($conversation, 'Hello');

        $this->assertEquals(ConversationEventType::USER_MESSAGE_CREATED, $result['event']->type);
    }

    public function test_assistant_message_event_has_correct_type(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client);

        $result = $this->recordAssistantMessage($conversation, 'How can I help?');

        $this->assertEquals(ConversationEventType::ASSISTANT_MESSAGE_CREATED, $result['event']->type);
    }

    public function test_generic_event_recording(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client);

        $result = $this->recordEvent(
            $conversation,
            ConversationEventType::VALUATION_REQUESTED,
            ['item' => 'vintage watch', 'condition' => 'good']
        );

        $this->assertTrue($result['created']);
        $this->assertEquals(ConversationEventType::VALUATION_REQUESTED, $result['event']->type);
        $this->assertEquals('vintage watch', $result['event']->payload['item']);
    }

    // =========================================================================
    // Event Immutability Invariants
    // =========================================================================

    public function test_updating_existing_event_throws_exception(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client);

        $result = $this->recordUserMessage($conversation, 'Original message');
        $event = $result['event'];

        // Attempting to update should throw
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('immutable');

        $event->payload = ['content' => 'Modified message'];
        $event->save();
    }

    public function test_deleting_event_throws_exception(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client);

        $result = $this->recordUserMessage($conversation, 'Message to delete');
        $event = $result['event'];

        // Attempting to delete should throw
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('immutable');

        $event->delete();
    }

    public function test_events_persist_after_failed_update_attempt(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client);

        $result = $this->recordUserMessage($conversation, 'Protected message');
        $event = $result['event'];
        $originalContent = $event->payload['content'];

        // Try to update (will throw)
        try {
            $event->payload = ['content' => 'Hacked!'];
            $event->save();
        } catch (\LogicException $e) {
            // Expected
        }

        // Refresh from DB and verify original content preserved
        $event->refresh();
        $this->assertEquals($originalContent, $event->payload['content']);
    }
}
