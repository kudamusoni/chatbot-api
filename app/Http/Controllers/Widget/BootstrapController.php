<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Http\Requests\Widget\BootstrapRequest;
use App\Models\Conversation;
use Illuminate\Http\JsonResponse;

class BootstrapController extends Controller
{
    /**
     * Create or resume a conversation.
     *
     * POST /api/widget/bootstrap
     */
    public function store(BootstrapRequest $request): JsonResponse
    {
        $clientId = $request->validated('client_id');
        $sessionToken = $request->validated('session_token');

        $conversation = null;
        $rawToken = null;

        // Try to resume existing conversation if token provided
        if ($sessionToken) {
            $conversation = Conversation::findByTokenForClient($sessionToken, $clientId);
            if ($conversation) {
                $rawToken = $sessionToken; // Keep the existing token
            }
        }

        // Create new conversation if no valid token or no token provided
        if (!$conversation) {
            [$conversation, $rawToken] = Conversation::createWithToken($clientId);
        }

        return response()->json([
            'session_token' => $rawToken,
            'conversation_id' => $conversation->id,
            'last_event_id' => $conversation->last_event_id ?? 0,
            'last_activity_at' => $conversation->last_activity_at?->toIso8601String(),
        ]);
    }
}
