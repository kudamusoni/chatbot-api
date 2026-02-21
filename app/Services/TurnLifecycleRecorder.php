<?php

namespace App\Services;

use App\Enums\ConversationEventType;
use App\Models\Conversation;
use App\Models\ConversationEvent;

class TurnLifecycleRecorder
{
    public function __construct(
        private readonly ConversationEventRecorder $eventRecorder
    ) {}

    public function recordStarted(
        Conversation $conversation,
        string $actionId,
        string $trigger,
        ?ConversationEvent $triggerEvent = null
    ): void {
        $this->eventRecorder->record(
            $conversation,
            ConversationEventType::TURN_STARTED,
            [
                'turn_id' => $actionId,
                'trigger' => $trigger,
                'trigger_event_id' => $triggerEvent?->id,
            ],
            idempotencyKey: "turn.started:{$actionId}",
            correlationId: $actionId
        );
    }

    public function recordCompleted(
        Conversation $conversation,
        string $actionId,
        string $trigger,
        int $latencyMs,
        ?ConversationEvent $triggerEvent = null
    ): void {
        $this->eventRecorder->record(
            $conversation,
            ConversationEventType::TURN_COMPLETED,
            [
                'turn_id' => $actionId,
                'trigger' => $trigger,
                'trigger_event_id' => $triggerEvent?->id,
                'latency_ms' => $latencyMs,
                'outcome' => 'success',
            ],
            idempotencyKey: "turn.completed:{$actionId}",
            correlationId: $actionId
        );
    }

    public function recordFailed(
        Conversation $conversation,
        string $actionId,
        string $trigger,
        int $latencyMs,
        ?\Throwable $error = null,
        ?ConversationEvent $triggerEvent = null
    ): void {
        $this->eventRecorder->record(
            $conversation,
            ConversationEventType::TURN_FAILED,
            [
                'turn_id' => $actionId,
                'trigger' => $trigger,
                'trigger_event_id' => $triggerEvent?->id,
                'latency_ms' => $latencyMs,
                'outcome' => 'failed',
                'error_code' => 'INTERNAL_ERROR',
                'error_message' => $error?->getMessage(),
            ],
            idempotencyKey: "turn.failed:{$actionId}",
            correlationId: $actionId
        );
    }
}
