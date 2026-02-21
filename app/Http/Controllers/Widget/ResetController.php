<?php

namespace App\Http\Controllers\Widget;

use App\Enums\ConversationEventType;
use App\Enums\ConversationState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Widget\ResetRequest;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Services\ConversationEventRecorder;
use App\Services\TurnLifecycleRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ResetController extends Controller
{
    private const INTRO_MESSAGE = 'Thank you for your message. How can I help you today?';

    public function __construct(
        private readonly ConversationEventRecorder $eventRecorder,
        private readonly TurnLifecycleRecorder $turnLifecycle
    ) {}

    /**
     * Reset conversation and start fresh with intro message.
     *
     * POST /api/widget/reset
     */
    public function store(ResetRequest $request): JsonResponse
    {
        $clientId = $request->validated('client_id');
        $sessionToken = $request->validated('session_token');
        $actionId = $request->validated('action_id');
        $introIdempotencyKey = "reset:{$actionId}:intro";

        $conversation = Conversation::findByTokenForClient($sessionToken, $clientId);

        if (!$conversation) {
            return response()->json([
                'error' => 'Invalid session token',
            ], 401);
        }

        // Idempotent retry: if this reset action already completed for current conversation, return it.
        $existingIntro = ConversationEvent::where('conversation_id', $conversation->id)
            ->where('idempotency_key', $introIdempotencyKey)
            ->where('type', ConversationEventType::ASSISTANT_MESSAGE_CREATED)
            ->first();

        if ($existingIntro) {
            $conversation->refresh();

            return response()->json([
                'ok' => true,
                'session_token' => $sessionToken,
                'conversation_id' => $conversation->id,
                'last_event_id' => $conversation->last_event_id,
            ]);
        }

        $startedAt = microtime(true);

        try {
            $conversation = DB::transaction(function () use ($conversation, $actionId, $introIdempotencyKey) {
                // Clear chat/event history while preserving the conversation and linked leads/valuations.
                DB::table('conversation_events')
                    ->where('conversation_id', $conversation->id)
                    ->delete();

                $conversation->update([
                    'state' => ConversationState::CHAT,
                    'context' => null,
                    'appraisal_answers' => null,
                    'appraisal_current_key' => null,
                    'appraisal_snapshot' => null,
                    'lead_answers' => null,
                    'lead_current_key' => null,
                    'lead_identity_candidate' => null,
                    'last_event_id' => 0,
                    'last_activity_at' => null,
                ]);

                $this->turnLifecycle->recordStarted($conversation, $actionId, 'reset');

                $this->eventRecorder->record(
                    $conversation,
                    ConversationEventType::ASSISTANT_MESSAGE_CREATED,
                    ['content' => self::INTRO_MESSAGE],
                    idempotencyKey: $introIdempotencyKey,
                    correlationId: $actionId
                );

                return $conversation;
            });

            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->turnLifecycle->recordCompleted($conversation, $actionId, 'reset', $latencyMs);
        } catch (\Throwable $e) {
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->turnLifecycle->recordFailed($conversation, $actionId, 'reset', $latencyMs, $e);
            throw $e;
        }

        $conversation->refresh();

        return response()->json([
            'ok' => true,
            'session_token' => $sessionToken,
            'conversation_id' => $conversation->id,
            'last_event_id' => $conversation->last_event_id,
        ]);
    }
}
