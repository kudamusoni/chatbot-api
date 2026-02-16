<?php

namespace Tests\Feature\Domain;

use App\Enums\ConversationEventType;
use App\Enums\ConversationState;
use App\Models\ConversationEvent;
use App\Models\Lead;
use App\Services\ConversationEventRecorder;
use App\Services\ConversationOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithConversations;
use Tests\TestCase;

class OrchestratorTest extends TestCase
{
    use RefreshDatabase, InteractsWithConversations;

    public function test_starts_appraisal_when_valuation_intent_detected(): void
    {
        $client = $this->makeClient();
        $this->makeAppraisalQuestion($client, ['key' => 'maker', 'order_index' => 1]);
        [$conversation, ] = $this->makeConversation($client);

        $eventRecorder = new ConversationEventRecorder();
        $orchestrator = new ConversationOrchestrator($eventRecorder);

        $userEvent = $eventRecorder->recordUserMessage(
            $conversation,
            'How much is this worth?'
        )['event'];

        $orchestrator->handleUserMessage($conversation, $userEvent);

        $events = ConversationEvent::where('conversation_id', $conversation->id)
            ->orderBy('id')
            ->get();

        $this->assertTrue($events->contains(fn ($e) => $e->type === ConversationEventType::APPRAISAL_STARTED));
        $this->assertTrue($events->contains(fn ($e) => $e->type === ConversationEventType::APPRAISAL_QUESTION_ASKED));

        $conversation->refresh();
        $this->assertEquals(ConversationState::APPRAISAL_INTAKE, $conversation->state);
    }

    public function test_records_answer_and_asks_next_question(): void
    {
        $client = $this->makeClient();
        $this->makeAppraisalQuestion($client, ['key' => 'maker', 'order_index' => 1]);
        $this->makeAppraisalQuestion($client, ['key' => 'age', 'order_index' => 2]);
        [$conversation, ] = $this->makeConversation($client, [
            'state' => ConversationState::APPRAISAL_INTAKE,
            'appraisal_current_key' => 'maker',
        ]);

        $eventRecorder = new ConversationEventRecorder();
        $orchestrator = new ConversationOrchestrator($eventRecorder);

        $userEvent = $eventRecorder->recordUserMessage(
            $conversation,
            'Royal Doulton'
        )['event'];

        $orchestrator->handleUserMessage($conversation, $userEvent);

        $events = ConversationEvent::where('conversation_id', $conversation->id)
            ->orderBy('id')
            ->get();

        $this->assertTrue($events->contains(fn ($e) => $e->type === ConversationEventType::APPRAISAL_ANSWER_RECORDED));
        $this->assertTrue($events->contains(fn ($e) => $e->type === ConversationEventType::APPRAISAL_QUESTION_ASKED));
    }

    public function test_completion_requests_confirmation_with_snapshot(): void
    {
        $client = $this->makeClient();
        $this->makeAppraisalQuestion($client, ['key' => 'maker', 'order_index' => 1]);
        $this->makeAppraisalQuestion($client, ['key' => 'age', 'order_index' => 2]);
        [$conversation, ] = $this->makeConversation($client, [
            'state' => ConversationState::APPRAISAL_INTAKE,
            'appraisal_current_key' => 'age',
            'appraisal_answers' => ['maker' => 'Royal Doulton'],
        ]);

        $eventRecorder = new ConversationEventRecorder();
        $orchestrator = new ConversationOrchestrator($eventRecorder);

        $userEvent = $eventRecorder->recordUserMessage(
            $conversation,
            'circa 1950'
        )['event'];

        $orchestrator->handleUserMessage($conversation, $userEvent);

        $confirmationEvent = ConversationEvent::where('conversation_id', $conversation->id)
            ->where('type', ConversationEventType::APPRAISAL_CONFIRMATION_REQUESTED)
            ->first();

        $this->assertNotNull($confirmationEvent);
        $this->assertEquals([
            'maker' => 'Royal Doulton',
            'age' => 'circa 1950',
        ], $confirmationEvent->payload['snapshot']);
    }

    public function test_starts_lead_when_requested_from_valuation_ready(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client, [
            'state' => ConversationState::VALUATION_READY,
        ]);

        $eventRecorder = new ConversationEventRecorder();
        $orchestrator = new ConversationOrchestrator($eventRecorder);

        $userEvent = $eventRecorder->recordUserMessage(
            $conversation,
            'Please request an expert manual review for this item.'
        )['event'];

        $orchestrator->handleUserMessage($conversation, $userEvent);

        $events = ConversationEvent::where('conversation_id', $conversation->id)
            ->orderBy('id')
            ->get();

        $this->assertTrue($events->contains(fn ($e) => $e->type === ConversationEventType::LEAD_STARTED));
        $this->assertTrue($events->contains(fn ($e) => $e->type === ConversationEventType::LEAD_QUESTION_ASKED));
        $this->assertTrue($events->contains(fn ($e) => $e->type === ConversationEventType::ASSISTANT_MESSAGE_CREATED));
    }

    public function test_requests_lead_identity_confirmation_when_latest_lead_exists(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client, [
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

        $eventRecorder = new ConversationEventRecorder();
        $orchestrator = new ConversationOrchestrator($eventRecorder);

        $userEvent = $eventRecorder->recordUserMessage(
            $conversation,
            'Please request an expert manual review for this item.'
        )['event'];

        $orchestrator->handleUserMessage($conversation, $userEvent);

        $this->assertTrue(
            ConversationEvent::where('conversation_id', $conversation->id)
                ->where('type', ConversationEventType::LEAD_IDENTITY_CONFIRMATION_REQUESTED)
                ->exists()
        );
        $this->assertFalse(
            ConversationEvent::where('conversation_id', $conversation->id)
                ->where('type', ConversationEventType::LEAD_STARTED)
                ->exists()
        );
    }

    public function test_starts_lead_with_loose_intent_phrase_from_valuation_ready(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client, [
            'state' => ConversationState::VALUATION_READY,
        ]);

        $eventRecorder = new ConversationEventRecorder();
        $orchestrator = new ConversationOrchestrator($eventRecorder);

        $userEvent = $eventRecorder->recordUserMessage(
            $conversation,
            'Can a specialist contact me to review this?'
        )['event'];

        $orchestrator->handleUserMessage($conversation, $userEvent);

        $this->assertTrue(
            ConversationEvent::where('conversation_id', $conversation->id)
                ->where('type', ConversationEventType::LEAD_STARTED)
                ->exists()
        );
    }

    public function test_lead_is_rejected_outside_valuation_ready(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client, [
            'state' => ConversationState::VALUATION_FAILED,
        ]);

        $eventRecorder = new ConversationEventRecorder();
        $orchestrator = new ConversationOrchestrator($eventRecorder);

        $userEvent = $eventRecorder->recordUserMessage(
            $conversation,
            'I want a lead.'
        )['event'];

        $orchestrator->handleUserMessage($conversation, $userEvent);

        $this->assertFalse(
            ConversationEvent::where('conversation_id', $conversation->id)
                ->where('type', ConversationEventType::LEAD_STARTED)
                ->exists()
        );

        $assistantEvents = ConversationEvent::where('conversation_id', $conversation->id)
            ->where('type', ConversationEventType::ASSISTANT_MESSAGE_CREATED)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $assistantEvents);
        $this->assertSame(
            'Lead capture is available after your valuation result is ready.',
            $assistantEvents[0]->payload['content']
        );
        $this->assertSame(
            'Do you have any more questions?',
            $assistantEvents[1]->payload['content']
        );

        $conversation->refresh();
        $this->assertSame(ConversationState::CHAT, $conversation->state);
    }

    public function test_lead_identity_confirm_state_requires_panel_decision(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client, [
            'state' => ConversationState::LEAD_IDENTITY_CONFIRM,
            'lead_identity_candidate' => [
                'name' => 'Jane Doe',
                'email' => 'jane@example.com',
                'phone_raw' => '+1 (202) 555-0110',
                'phone_normalized' => '+12025550110',
            ],
        ]);

        $eventRecorder = new ConversationEventRecorder();
        $orchestrator = new ConversationOrchestrator($eventRecorder);

        $userEvent = $eventRecorder->recordUserMessage(
            $conversation,
            'yes'
        )['event'];

        $orchestrator->handleUserMessage($conversation, $userEvent);

        $this->assertTrue(
            ConversationEvent::where('conversation_id', $conversation->id)
                ->where('type', ConversationEventType::ASSISTANT_MESSAGE_CREATED)
                ->where('payload->content', 'Please use the Yes or No buttons to confirm your contact details.')
                ->exists()
        );
    }
}
