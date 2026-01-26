<?php

namespace Tests\Feature\Domain;

use App\Enums\ConversationEventType;
use App\Enums\ConversationState;
use App\Enums\ValuationStatus;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Valuation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithConversations;
use Tests\TestCase;

/**
 * Tests for projection invariants.
 * Verifies that events correctly update read-optimized tables.
 */
class ProjectionTest extends TestCase
{
    use RefreshDatabase, InteractsWithConversations;

    // =========================================================================
    // Message Projection Invariants
    // =========================================================================

    public function test_user_message_creates_message_projection_with_user_role(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client);

        $this->recordUserMessage($conversation, 'Hello, I need help');

        $message = ConversationMessage::where('conversation_id', $conversation->id)->first();

        $this->assertNotNull($message);
        $this->assertEquals('user', $message->role);
        $this->assertEquals('Hello, I need help', $message->content);
        $this->assertEquals($client->id, $message->client_id);
    }

    public function test_assistant_message_creates_message_projection_with_assistant_role(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client);

        $this->recordAssistantMessage($conversation, 'How can I assist you today?');

        $message = ConversationMessage::where('conversation_id', $conversation->id)->first();

        $this->assertNotNull($message);
        $this->assertEquals('assistant', $message->role);
        $this->assertEquals('How can I assist you today?', $message->content);
    }

    public function test_message_projection_links_to_source_event(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client);

        $result = $this->recordUserMessage($conversation, 'Test message');
        $event = $result['event'];

        $message = ConversationMessage::where('conversation_id', $conversation->id)->first();

        $this->assertEquals($event->id, $message->event_id);
    }

    public function test_multiple_messages_create_multiple_projections(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client);

        $this->recordUserMessage($conversation, 'First message');
        $this->recordAssistantMessage($conversation, 'Second message');
        $this->recordUserMessage($conversation, 'Third message');

        $messages = ConversationMessage::where('conversation_id', $conversation->id)
            ->orderBy('event_id')
            ->get();

        $this->assertCount(3, $messages);
        $this->assertEquals('user', $messages[0]->role);
        $this->assertEquals('assistant', $messages[1]->role);
        $this->assertEquals('user', $messages[2]->role);
    }

    public function test_message_projection_is_idempotent(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client);

        // Record a message
        $result = $this->recordUserMessage($conversation, 'Hello');
        $event = $result['event'];

        // Manually re-run the projector (simulating replay)
        $projector = new \App\Projectors\ConversationProjector();
        $projector->handle(new \App\Events\Conversation\ConversationEventRecorded($event));
        $projector->handle(new \App\Events\Conversation\ConversationEventRecorded($event));

        // Should still only have one message
        $messageCount = ConversationMessage::where('conversation_id', $conversation->id)->count();
        $this->assertEquals(1, $messageCount);
    }

    // =========================================================================
    // Conversation Projection Invariants
    // =========================================================================

    public function test_conversation_last_event_id_updates_on_event(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client);

        $this->assertEquals(0, $conversation->last_event_id);

        $result1 = $this->recordUserMessage($conversation, 'First');
        $conversation->refresh();
        $this->assertEquals($result1['event']->id, $conversation->last_event_id);

        $result2 = $this->recordAssistantMessage($conversation, 'Second');
        $conversation->refresh();
        $this->assertEquals($result2['event']->id, $conversation->last_event_id);
    }

    public function test_conversation_last_activity_at_updates_on_event(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client);

        $this->assertNull($conversation->last_activity_at);

        $this->recordUserMessage($conversation, 'Hello');
        $conversation->refresh();

        $this->assertNotNull($conversation->last_activity_at);
        // Should be recent (within last minute)
        $this->assertTrue($conversation->last_activity_at->isAfter(now()->subMinute()));
    }

    public function test_non_message_events_also_update_conversation_projection(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client);

        $result = $this->recordEvent(
            $conversation,
            ConversationEventType::APPRAISAL_QUESTION_ASKED,
            ['question' => 'What is the item condition?']
        );

        $conversation->refresh();

        $this->assertEquals($result['event']->id, $conversation->last_event_id);
        $this->assertNotNull($conversation->last_activity_at);
    }

    // =========================================================================
    // State Transition Invariants
    // =========================================================================

    public function test_appraisal_question_transitions_state_to_intake(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client);

        $this->assertEquals(ConversationState::CHAT, $conversation->state);

        $this->recordEvent(
            $conversation,
            ConversationEventType::APPRAISAL_QUESTION_ASKED,
            ['question' => 'What type of item?']
        );

        $conversation->refresh();
        $this->assertEquals(ConversationState::APPRAISAL_INTAKE, $conversation->state);
    }

    public function test_appraisal_confirmation_requested_transitions_state_and_stores_context(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client);

        $appraisalData = [
            'item_type' => 'watch',
            'brand' => 'Rolex',
            'condition' => 'excellent',
        ];

        $this->recordEvent(
            $conversation,
            ConversationEventType::APPRAISAL_CONFIRMATION_REQUESTED,
            ['appraisal' => $appraisalData]
        );

        $conversation->refresh();

        $this->assertEquals(ConversationState::APPRAISAL_CONFIRM, $conversation->state);
        $this->assertEquals($appraisalData, $conversation->context['appraisal']);
    }

    public function test_appraisal_confirmed_transitions_state_to_valuation_running(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client);

        // First show confirmation
        $this->recordEvent(
            $conversation,
            ConversationEventType::APPRAISAL_CONFIRMATION_REQUESTED,
            ['appraisal' => ['item' => 'watch']]
        );

        // Then user confirms
        $this->recordEvent(
            $conversation,
            ConversationEventType::APPRAISAL_CONFIRMED,
            []
        );

        $conversation->refresh();
        $this->assertEquals(ConversationState::VALUATION_RUNNING, $conversation->state);
    }

    public function test_valuation_requested_transitions_state_to_running(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client);

        $this->recordEvent(
            $conversation,
            ConversationEventType::VALUATION_REQUESTED,
            ['item' => 'vintage watch']
        );

        $conversation->refresh();
        $this->assertEquals(ConversationState::VALUATION_RUNNING, $conversation->state);
    }

    public function test_valuation_completed_transitions_state_to_ready(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client);

        // First request valuation
        $this->recordEvent(
            $conversation,
            ConversationEventType::VALUATION_REQUESTED,
            ['item' => 'vintage watch']
        );

        // Then complete it
        $this->recordEvent(
            $conversation,
            ConversationEventType::VALUATION_COMPLETED,
            ['result' => ['estimated_value' => 5000]]
        );

        $conversation->refresh();
        $this->assertEquals(ConversationState::VALUATION_READY, $conversation->state);
    }

    // =========================================================================
    // Valuation Projection Invariants
    // =========================================================================

    public function test_valuation_requested_creates_valuation_projection(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client);

        $inputData = [
            'item_type' => 'watch',
            'brand' => 'Omega',
            'year' => 1970,
        ];

        $result = $this->recordEvent(
            $conversation,
            ConversationEventType::VALUATION_REQUESTED,
            $inputData
        );

        $valuation = Valuation::where('conversation_id', $conversation->id)->first();

        $this->assertNotNull($valuation);
        $this->assertEquals($client->id, $valuation->client_id);
        $this->assertEquals($result['event']->id, $valuation->request_event_id);
        $this->assertEquals(ValuationStatus::PENDING, $valuation->status);
        $this->assertEquals($inputData, $valuation->input_snapshot);
    }

    public function test_valuation_completed_updates_valuation_status_and_result(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client);

        // Request valuation
        $this->recordEvent(
            $conversation,
            ConversationEventType::VALUATION_REQUESTED,
            ['item' => 'antique vase']
        );

        $valuationResult = [
            'estimated_value' => 2500,
            'confidence' => 'high',
            'comparables' => ['similar_vase_1', 'similar_vase_2'],
        ];

        // Complete valuation
        $this->recordEvent(
            $conversation,
            ConversationEventType::VALUATION_COMPLETED,
            ['result' => $valuationResult]
        );

        $valuation = Valuation::where('conversation_id', $conversation->id)->first();

        $this->assertEquals(ValuationStatus::COMPLETED, $valuation->status);
        $this->assertEquals($valuationResult, $valuation->result);
    }

    public function test_duplicate_valuation_request_is_idempotent_via_snapshot_hash(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client);

        $inputData = ['item' => 'painting', 'artist' => 'Unknown'];

        // Request twice with same data
        $this->recordEvent($conversation, ConversationEventType::VALUATION_REQUESTED, $inputData);
        $this->recordEvent($conversation, ConversationEventType::VALUATION_REQUESTED, $inputData);

        // Should only create one valuation (same snapshot hash)
        $count = Valuation::where('conversation_id', $conversation->id)->count();
        $this->assertEquals(1, $count);
    }

    // =========================================================================
    // Tenant Isolation in Projections
    // =========================================================================

    public function test_message_projections_include_client_id(): void
    {
        $clientA = $this->makeClient(['name' => 'Client A', 'slug' => 'client-a-' . uniqid()]);
        $clientB = $this->makeClient(['name' => 'Client B', 'slug' => 'client-b-' . uniqid()]);

        [$convA, ] = $this->makeConversation($clientA);
        [$convB, ] = $this->makeConversation($clientB);

        $this->recordUserMessage($convA, 'Message for A');
        $this->recordUserMessage($convB, 'Message for B');

        $messagesA = ConversationMessage::forClient($clientA->id)->get();
        $messagesB = ConversationMessage::forClient($clientB->id)->get();

        $this->assertCount(1, $messagesA);
        $this->assertCount(1, $messagesB);
        $this->assertEquals('Message for A', $messagesA->first()->content);
        $this->assertEquals('Message for B', $messagesB->first()->content);
    }

    public function test_valuation_projections_include_client_id(): void
    {
        $clientA = $this->makeClient(['name' => 'Client A', 'slug' => 'client-a-' . uniqid()]);
        $clientB = $this->makeClient(['name' => 'Client B', 'slug' => 'client-b-' . uniqid()]);

        [$convA, ] = $this->makeConversation($clientA);
        [$convB, ] = $this->makeConversation($clientB);

        $this->recordEvent($convA, ConversationEventType::VALUATION_REQUESTED, ['item' => 'Item A']);
        $this->recordEvent($convB, ConversationEventType::VALUATION_REQUESTED, ['item' => 'Item B']);

        $valuationsA = Valuation::forClient($clientA->id)->get();
        $valuationsB = Valuation::forClient($clientB->id)->get();

        $this->assertCount(1, $valuationsA);
        $this->assertCount(1, $valuationsB);
    }

    // =========================================================================
    // Projection Isolation Across Conversations
    // =========================================================================

    public function test_messages_in_different_conversations_are_isolated(): void
    {
        $client = $this->makeClient();
        [$conv1, ] = $this->makeConversation($client);
        [$conv2, ] = $this->makeConversation($client);

        // Record messages in both conversations
        $this->recordUserMessage($conv1, 'Message 1 in Conv1');
        $this->recordAssistantMessage($conv1, 'Response 1 in Conv1');
        $this->recordUserMessage($conv2, 'Message 1 in Conv2');

        // Each conversation should only have its own messages
        $conv1Messages = ConversationMessage::where('conversation_id', $conv1->id)->get();
        $conv2Messages = ConversationMessage::where('conversation_id', $conv2->id)->get();

        $this->assertCount(2, $conv1Messages);
        $this->assertCount(1, $conv2Messages);

        // Verify content isolation
        $conv1Contents = $conv1Messages->pluck('content')->toArray();
        $conv2Contents = $conv2Messages->pluck('content')->toArray();

        $this->assertContains('Message 1 in Conv1', $conv1Contents);
        $this->assertContains('Response 1 in Conv1', $conv1Contents);
        $this->assertNotContains('Message 1 in Conv2', $conv1Contents);

        $this->assertContains('Message 1 in Conv2', $conv2Contents);
        $this->assertNotContains('Message 1 in Conv1', $conv2Contents);
    }

    public function test_last_event_id_updates_only_for_own_conversation(): void
    {
        $client = $this->makeClient();
        [$conv1, ] = $this->makeConversation($client);
        [$conv2, ] = $this->makeConversation($client);

        // Record in conv1
        $result1 = $this->recordUserMessage($conv1, 'Message in Conv1');

        // Record in conv2
        $result2 = $this->recordUserMessage($conv2, 'Message in Conv2');

        // Each conversation should track only its own last_event_id
        $conv1->refresh();
        $conv2->refresh();

        $this->assertEquals($result1['event']->id, $conv1->last_event_id);
        $this->assertEquals($result2['event']->id, $conv2->last_event_id);
        $this->assertNotEquals($conv1->last_event_id, $conv2->last_event_id);
    }

    // =========================================================================
    // Conversation Activity Invariants
    // =========================================================================

    public function test_last_activity_at_is_monotonic(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client);

        // Record first message
        $this->recordUserMessage($conversation, 'First');
        $conversation->refresh();
        $firstActivity = $conversation->last_activity_at;

        // Small delay to ensure timestamp difference
        usleep(10000); // 10ms

        // Record second message
        $this->recordAssistantMessage($conversation, 'Second');
        $conversation->refresh();
        $secondActivity = $conversation->last_activity_at;

        // Activity should move forward (or stay same if within same second)
        $this->assertTrue(
            $secondActivity->greaterThanOrEqualTo($firstActivity),
            'last_activity_at should be monotonic (non-decreasing)'
        );
    }

    public function test_last_activity_at_updates_on_all_event_types(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client);

        $this->assertNull($conversation->last_activity_at);

        // User message
        $this->recordUserMessage($conversation, 'Hello');
        $conversation->refresh();
        $this->assertNotNull($conversation->last_activity_at);
        $afterUserMessage = $conversation->last_activity_at;

        // Appraisal event
        $this->recordEvent($conversation, ConversationEventType::APPRAISAL_QUESTION_ASKED, ['q' => 'test']);
        $conversation->refresh();
        $this->assertTrue($conversation->last_activity_at->greaterThanOrEqualTo($afterUserMessage));

        // Valuation event
        $this->recordEvent($conversation, ConversationEventType::VALUATION_REQUESTED, ['item' => 'test']);
        $conversation->refresh();
        $this->assertNotNull($conversation->last_activity_at);
    }

    public function test_last_event_id_and_last_activity_at_update_together(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client);

        $this->assertEquals(0, $conversation->last_event_id);
        $this->assertNull($conversation->last_activity_at);

        $result = $this->recordUserMessage($conversation, 'Test message');
        $conversation->refresh();

        // Both should update atomically
        $this->assertEquals($result['event']->id, $conversation->last_event_id);
        $this->assertNotNull($conversation->last_activity_at);
        $this->assertEquals(
            $result['event']->created_at->format('Y-m-d H:i:s'),
            $conversation->last_activity_at->format('Y-m-d H:i:s')
        );
    }
}
