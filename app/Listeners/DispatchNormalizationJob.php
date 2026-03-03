<?php

namespace App\Listeners;

use App\Enums\ConversationEventType;
use App\Events\Conversation\ConversationEventRecorded;
use App\Jobs\NormalizeAppraisalAnswerJob;

class DispatchNormalizationJob
{
    public function handle(ConversationEventRecorded $eventRecorded): void
    {
        $event = $eventRecorded->event;
        if ($event->type !== ConversationEventType::APPRAISAL_ANSWER_NORMALIZATION_REQUESTED) {
            return;
        }

        $turnId = (string) ($event->payload['turn_id'] ?? '');
        $requestEventId = (int) ($event->payload['request_event_id'] ?? 0);
        $questionKey = (string) ($event->payload['question_key'] ?? '');
        $rawValue = (string) ($event->payload['raw_value'] ?? '');

        if ($turnId === '' || $requestEventId <= 0 || $questionKey === '') {
            return;
        }

        NormalizeAppraisalAnswerJob::dispatch(
            clientId: (string) $event->client_id,
            conversationId: (string) $event->conversation_id,
            requestEventId: $requestEventId,
            turnId: $turnId,
            questionKey: $questionKey,
            rawValue: $rawValue
        );
    }
}

