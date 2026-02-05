<?php

namespace Tests\Feature\Domain;

use App\Enums\ConversationEventType;
use App\Enums\ConversationState;
use App\Models\ConversationEvent;
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
}
