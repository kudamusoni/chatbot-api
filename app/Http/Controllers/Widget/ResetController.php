<?php

namespace App\Http\Controllers\Widget;

use App\Enums\ConversationEventType;
use App\Enums\ConversationState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Widget\ResetRequest;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Services\ConversationEventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ResetController extends Controller
{
    private const INTRO_MESSAGE = 'Hello. How can I help you today?';

    public function __construct(
        private readonly ConversationEventRecorder $eventRecorder
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

        $conversation = DB::transaction(function () use ($conversation, $clientId, $sessionToken, $actionId, $introIdempotencyKey) {
            // Delete existing conversation (cascade clears events/messages/valuations/leads).
            $conversation->delete();

            // Recreate a fresh conversation while preserving the same session token.
            $freshConversation = Conversation::create([
                'client_id' => $clientId,
                'session_token_hash' => Conversation::hashSessionToken($sessionToken),
                'state' => ConversationState::CHAT,
            ]);

            $this->eventRecorder->record(
                $freshConversation,
                ConversationEventType::ASSISTANT_MESSAGE_CREATED,
                ['content' => self::INTRO_MESSAGE],
                idempotencyKey: $introIdempotencyKey,
                correlationId: $actionId
            );

            return $freshConversation;
        });

        $conversation->refresh();

        return response()->json([
            'ok' => true,
            'session_token' => $sessionToken,
            'conversation_id' => $conversation->id,
            'last_event_id' => $conversation->last_event_id,
        ]);
    }
}
