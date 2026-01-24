<?php

namespace App\Events\Conversation;

use App\Models\ConversationEvent;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when a new conversation event is recorded.
 *
 * This is a standard Laravel event (does not implement ShouldBroadcast).
 * In Step 3, after recording events, we publish to Redis channel
 * `conversation:{id}` for SSE subscribers.
 */
class ConversationEventRecorded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly ConversationEvent $event
    ) {}
}
