<?php

namespace App\Http\Controllers\App;

use App\Enums\AppDenyReason;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\ConversationMessage;
use App\Support\ConversationMessagePresenter;
use App\Support\CurrentClient;
use App\Support\DashboardListDefaults;
use App\Support\DashboardRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var CurrentClient $currentClient */
        $currentClient = app(CurrentClient::class);
        $perPage = DashboardListDefaults::perPage($request);

        $query = Conversation::query()
            ->where('client_id', $currentClient->id())
            ->select('conversations.*')
            ->selectSub(function ($sub) {
                $sub->from('conversation_messages')
                    ->whereColumn('conversation_messages.conversation_id', 'conversations.id')
                    ->orderByDesc('event_id')
                    ->limit(1)
                    ->select('content');
            }, 'last_message_preview')
            ->selectSub(function ($sub) {
                $sub->from('leads')
                    ->whereColumn('leads.conversation_id', 'conversations.id')
                    ->selectRaw('count(*)');
            }, 'lead_count')
            ->selectSub(function ($sub) {
                $sub->from('valuations')
                    ->whereColumn('valuations.conversation_id', 'conversations.id')
                    ->orderByDesc('created_at')
                    ->limit(1)
                    ->select('status');
            }, 'valuation_status');

        $range = (string) $request->query('range', 'all');
        $this->applyRange($query, $range, 'last_activity_at');

        if ($request->filled('state')) {
            $query->where('state', (string) $request->query('state'));
        }

        if ($request->filled('q')) {
            $term = trim((string) $request->query('q'));
            $query->where(function ($q) use ($term): void {
                $q->where('conversations.id', 'like', "%{$term}%")
                    ->orWhereExists(function ($sub) use ($term) {
                        $sub->selectRaw('1')
                            ->from('conversation_messages')
                            ->whereColumn('conversation_messages.conversation_id', 'conversations.id')
                            ->where('conversation_messages.content', 'ilike', "%{$term}%");
                    });
            });
        }

        DashboardListDefaults::applyDefaultSort($query, 'conversations');

        $items = $query->paginate($perPage)->through(fn (Conversation $conversation) => [
            'id' => $conversation->id,
            'state' => $conversation->state->value,
            'last_activity_at' => $conversation->last_activity_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'last_message_preview' => $conversation->getAttribute('last_message_preview'),
            'lead_count' => (int) ($conversation->getAttribute('lead_count') ?? 0),
            'valuation_status' => $conversation->getAttribute('valuation_status'),
        ]);

        return response()->json(DashboardListDefaults::withDefaultSortMeta($items, 'conversations'));
    }

    public function messages(string $id): JsonResponse
    {
        /** @var CurrentClient $currentClient */
        $currentClient = app(CurrentClient::class);

        Conversation::query()
            ->where('client_id', $currentClient->id())
            ->where('id', $id)
            ->firstOrFail();

        $messages = ConversationMessage::query()
            ->where('client_id', $currentClient->id())
            ->where('conversation_id', $id)
            ->orderBy('event_id')
            ->get()
            ->map(fn (ConversationMessage $message) => ConversationMessagePresenter::present($message))
            ->values();

        return response()->json(['data' => $messages]);
    }

    public function events(Request $request, string $id): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        if (!$user->isPlatformAdmin()) {
            return response()->json([
                'error' => 'FORBIDDEN',
                'reason_code' => AppDenyReason::INSUFFICIENT_ROLE->value,
            ], 403);
        }

        /** @var CurrentClient $currentClient */
        $currentClient = app(CurrentClient::class);

        Conversation::query()
            ->where('client_id', $currentClient->id())
            ->where('id', $id)
            ->firstOrFail();

        $events = ConversationEvent::query()
            ->where('client_id', $currentClient->id())
            ->where('conversation_id', $id)
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->map(fn (ConversationEvent $event) => [
                'id' => $event->id,
                'type' => $event->type->value,
                'payload' => $event->payload,
                'created_at' => $event->created_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            ])
            ->values();

        return response()->json(['data' => $events]);
    }

    private function applyRange($query, string $range, string $column): void
    {
        try {
            $parsed = DashboardRange::parse($range);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages([
                'range' => ['The selected range is invalid.'],
            ]);
        }

        if ($parsed->from !== null) {
            $query->whereBetween($column, [$parsed->from, $parsed->to]);
        }
    }
}
