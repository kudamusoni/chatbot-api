<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    /**
     * List conversations for a client.
     *
     * GET /api/admin/conversations?client_id=...
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'client_id' => ['required', 'uuid', 'exists:clients,id'],
        ]);

        $clientId = $request->query('client_id');
        $user = $request->user();

        // Check user has access to this client
        if (!$user->hasAccessToClient($clientId)) {
            return response()->json([
                'error' => 'Access denied to this client',
            ], 403);
        }

        $conversations = Conversation::forClient($clientId)
            ->orderByRaw('last_activity_at DESC NULLS LAST')
            ->select(['id', 'client_id', 'state', 'last_event_id', 'last_activity_at', 'created_at'])
            ->paginate(50);

        return response()->json([
            'conversations' => $conversations->items(),
            'pagination' => [
                'current_page' => $conversations->currentPage(),
                'last_page' => $conversations->lastPage(),
                'per_page' => $conversations->perPage(),
                'total' => $conversations->total(),
            ],
        ]);
    }

    /**
     * Get messages for a conversation.
     *
     * GET /api/admin/conversations/{conversation}/messages
     */
    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();

        // Check user has access to the conversation's client
        if (!$user->hasAccessToClient($conversation->client_id)) {
            return response()->json([
                'error' => 'Access denied to this conversation',
            ], 403);
        }

        $messages = $conversation->messages()
            ->orderBy('event_id')
            ->select(['id', 'conversation_id', 'event_id', 'role', 'content', 'created_at'])
            ->get()
            ->map(fn ($message) => [
                'id' => $message->id,
                'event_id' => $message->event_id,
                'role' => $message->role,
                'content' => $message->content,
                'created_at' => $message->created_at->toIso8601String(),
            ]);

        return response()->json([
            'conversation_id' => $conversation->id,
            'messages' => $messages,
        ]);
    }

    /**
     * Get raw events for a conversation (debugging endpoint).
     *
     * GET /api/admin/conversations/{conversation}/events
     */
    public function events(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();

        // Check user has access to the conversation's client
        if (!$user->hasAccessToClient($conversation->client_id)) {
            return response()->json([
                'error' => 'Access denied to this conversation',
            ], 403);
        }

        // Optional: filter events after a specific ID (for pagination/replay)
        $afterId = $request->query('after_id', 0);

        $events = $conversation->events()
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->map(fn ($event) => [
                'id' => $event->id,
                'type' => $event->type->value,
                'payload' => $event->payload,
                'correlation_id' => $event->correlation_id,
                'idempotency_key' => $event->idempotency_key,
                'created_at' => $event->created_at->toIso8601String(),
            ]);

        return response()->json([
            'conversation_id' => $conversation->id,
            'events' => $events,
            'has_more' => $events->count() === 100,
        ]);
    }
}
