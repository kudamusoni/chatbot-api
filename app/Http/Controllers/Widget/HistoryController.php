<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Valuation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class HistoryController extends Controller
{
    /**
     * Default message limit.
     */
    private const DEFAULT_LIMIT = 200;

    /**
     * Maximum message limit.
     */
    private const MAX_LIMIT = 500;

    /**
     * Get conversation history for fast initial load.
     *
     * GET /api/widget/history
     *
     * This endpoint provides immediate transcript render via HTTP,
     * then the widget can connect to SSE with after_id=last_event_id for live updates.
     */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'client_id' => 'required|uuid',
            'session_token' => 'required|string|size:64',
            'limit' => 'sometimes|integer|min:1|max:' . self::MAX_LIMIT,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $clientId = $request->query('client_id');
        $sessionToken = $request->query('session_token');
        $limit = (int) $request->query('limit', self::DEFAULT_LIMIT);

        // Find conversation by token + client (tenant-scoped)
        $conversation = Conversation::findByTokenForClient($sessionToken, $clientId);

        if (!$conversation) {
            return response()->json(['error' => 'Invalid session token'], 401);
        }

        // Get messages ordered by event_id
        $messages = ConversationMessage::where('conversation_id', $conversation->id)
            ->orderBy('event_id', 'asc')
            ->limit($limit)
            ->get()
            ->map(fn ($msg) => [
                'event_id' => $msg->event_id,
                'role' => $msg->role,
                'content' => $msg->content,
                'created_at' => $msg->created_at->toIso8601String(),
            ]);

        // Get latest valuation if exists
        $valuation = Valuation::where('conversation_id', $conversation->id)
            ->orderByDesc('updated_at')
            ->first();

        return response()->json([
            'conversation_id' => $conversation->id,
            'last_event_id' => $conversation->last_event_id ?? 0,
            'messages' => $messages,
            // State + raw projection fields — frontend derives panel type
            'state' => $conversation->state->value,
            'appraisal_current_key' => $conversation->appraisal_current_key,
            'appraisal_snapshot' => $conversation->appraisal_snapshot,
            'lead_current_key' => $conversation->lead_current_key,
            'lead_answers' => $conversation->lead_answers,
            'lead_identity_candidate' => $conversation->lead_identity_candidate,
            'valuation' => $valuation ? [
                'status' => $valuation->status->value,
                'result' => $valuation->result,
            ] : null,
        ]);
    }
}
