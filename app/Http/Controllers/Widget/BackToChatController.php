<?php

namespace App\Http\Controllers\Widget;

use App\Enums\ConversationEventType;
use App\Enums\ConversationState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Widget\BackToChatRequest;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Services\ConversationEventRecorder;
use App\Services\TurnLifecycleRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class BackToChatController extends Controller
{
    private const FOLLOW_UP_MESSAGE = 'Do you have any more questions?';

    public function __construct(
        private readonly ConversationEventRecorder $eventRecorder,
        private readonly TurnLifecycleRecorder $turnLifecycle
    ) {}

    /**
     * Force conversation back to chat mode and emit a follow-up assistant message.
     *
     * POST /api/widget/back-to-chat
     */
    public function store(BackToChatRequest $request): JsonResponse
    {
        $clientId = $request->validated('client_id');
        $sessionToken = $request->validated('session_token');
        $actionId = $request->validated('action_id');
        $assistantIdempotencyKey = "back_to_chat:{$actionId}:assistant";

        $conversation = Conversation::findByTokenForClient($sessionToken, $clientId);

        if (!$conversation) {
            return response()->json([
                'error' => 'Invalid session token',
            ], 401);
        }

        $existingAssistantMessage = ConversationEvent::where('conversation_id', $conversation->id)
            ->where('idempotency_key', $assistantIdempotencyKey)
            ->where('type', ConversationEventType::ASSISTANT_MESSAGE_CREATED)
            ->first();

        if ($existingAssistantMessage) {
            $conversation->refresh();

            return response()->json([
                'ok' => true,
                'conversation_id' => $conversation->id,
                'last_event_id' => $conversation->last_event_id,
            ]);
        }

        $startedAt = microtime(true);
        $this->turnLifecycle->recordStarted($conversation, $actionId, 'back_to_chat');

        try {
            DB::transaction(function () use ($conversation, $actionId, $assistantIdempotencyKey) {
                $conversation->update([
                    'state' => ConversationState::CHAT,
                    'appraisal_answers' => null,
                    'appraisal_current_key' => null,
                    'appraisal_snapshot' => null,
                    'lead_answers' => null,
                    'lead_current_key' => null,
                    'lead_identity_candidate' => null,
                ]);

                $this->eventRecorder->record(
                    $conversation,
                    ConversationEventType::ASSISTANT_MESSAGE_CREATED,
                    ['content' => self::FOLLOW_UP_MESSAGE],
                    idempotencyKey: $assistantIdempotencyKey,
                    correlationId: $actionId
                );
            });

            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->turnLifecycle->recordCompleted($conversation, $actionId, 'back_to_chat', $latencyMs);
        } catch (\Throwable $e) {
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->turnLifecycle->recordFailed($conversation, $actionId, 'back_to_chat', $latencyMs, $e);
            throw $e;
        }

        $conversation->refresh();

        return response()->json([
            'ok' => true,
            'conversation_id' => $conversation->id,
            'last_event_id' => $conversation->last_event_id,
        ]);
    }
}
