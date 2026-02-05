<?php

namespace App\Http\Controllers\Widget;

use App\Enums\ConversationEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Widget\ChatRequest;
use App\Models\Conversation;
use App\Services\ConversationEventRecorder;
use App\Services\ConversationOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChatController extends Controller
{
    public function __construct(
        private readonly ConversationEventRecorder $eventRecorder,
        private readonly ConversationOrchestrator $orchestrator
    ) {}

    /**
     * Send a message in a conversation.
     *
     * POST /api/widget/chat
     */
    public function store(ChatRequest $request): JsonResponse
    {
        $clientId = $request->validated('client_id');
        $sessionToken = $request->validated('session_token');
        $messageId = $request->validated('message_id');
        $text = $request->validated('text');

        // Find conversation by token and client (tenant-scoped)
        $conversation = Conversation::findByTokenForClient($sessionToken, $clientId);

        if (!$conversation) {
            return response()->json([
                'error' => 'Invalid session token',
            ], 401);
        }

        // Generate correlation ID for this request (traces all events from this request)
        $correlationId = (string) Str::uuid();

        // Wrap both event writes in a transaction for atomicity
        // Ensures "each user turn gets exactly one assistant response"
        DB::transaction(function () use ($conversation, $text, $messageId, $correlationId) {
            // Record user message with idempotency key
            $userResult = $this->eventRecorder->record(
                $conversation,
                ConversationEventType::USER_MESSAGE_CREATED,
                ['content' => $text],
                idempotencyKey: $messageId,
                correlationId: $correlationId
            );

            // Only record assistant message if user message was newly created (not a retry)
            if ($userResult['created']) {
                $this->orchestrator->handleUserMessage($conversation, $userResult['event']);
            }
        });

        // Refresh conversation to get updated last_event_id
        $conversation->refresh();

        return response()->json([
            'ok' => true,
            'conversation_id' => $conversation->id,
            'correlation_id' => $correlationId,
            'last_event_id' => $conversation->last_event_id,
        ]);
    }
}
