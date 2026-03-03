<?php

namespace App\Listeners;

use App\Enums\ConversationEventType;
use App\Events\Conversation\ConversationEventRecorded;
use App\Jobs\RunAssistantResponseJob;

class DispatchAssistantResponseJob
{
    public function handle(ConversationEventRecorded $eventRecorded): void
    {
        $event = $eventRecorded->event;
        if ($event->type !== ConversationEventType::ASSISTANT_RESPONSE_REQUESTED) {
            return;
        }

        $turnId = (string) ($event->payload['turn_id'] ?? '');
        $requestEventId = (int) ($event->payload['request_event_id'] ?? 0);
        if ($turnId === '' || $requestEventId <= 0) {
            return;
        }

        RunAssistantResponseJob::dispatch(
            clientId: (string) $event->client_id,
            conversationId: (string) $event->conversation_id,
            requestEventId: $requestEventId,
            turnId: $turnId
        );
    }
}

