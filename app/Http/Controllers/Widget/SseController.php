<?php

namespace App\Http\Controllers\Widget;

use App\Enums\WidgetDenyReason;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SseController extends Controller
{
    /**
     * Maximum duration for keepalive loop (seconds).
     */
    private const MAX_DURATION = 60;

    /**
     * Interval between keepalive pings (seconds).
     */
    private const PING_INTERVAL = 15;

    /**
     * Reconnect delay for clients (milliseconds).
     * Sent via SSE `retry:` directive.
     */
    private const RETRY_MS = 2000;

    /**
     * Output buffer for test mode.
     */
    private string $outputBuffer = '';

    /**
     * Whether we're in test mode.
     */
    private bool $testMode = false;

    /**
     * Stream conversation events via Server-Sent Events.
     *
     * GET /api/widget/sse
     */
    public function stream(Request $request): StreamedResponse|Response|JsonResponse
    {
        // Get client_id: header preferred, query fallback
        $clientId = $request->header('X-Client-Id') ?? $request->query('client_id');

        // Get session_token: header preferred, query fallback
        $sessionToken = $request->header('X-Session-Token') ?? $request->query('session_token');

        // Validate required inputs
        if (!$clientId || !$sessionToken) {
            return $this->unauthorizedResponse('Missing client_id or session_token');
        }

        // Find conversation by token + client (tenant-scoped)
        $conversation = Conversation::findByTokenForClient($sessionToken, $clientId);

        if (!$conversation) {
            return $this->unauthorizedResponse('Invalid session token');
        }

        // Determine cursor: Last-Event-ID header > after_id query > 0
        $cursorRaw = $request->header('Last-Event-ID')
            ?? $request->query('after_id')
            ?? 0;
        $cursor = (int) $cursorRaw;

        if ($cursor < 0) {
            return response()->json([
                'error' => 'Invalid cursor',
            ], 422);
        }

        $latestEventId = (int) (ConversationEvent::where('conversation_id', $conversation->id)->max('id') ?? 0);

        if ($cursor > $latestEventId) {
            return $this->resyncRequired(
                WidgetDenyReason::CURSOR_AHEAD_OF_LATEST,
                $conversation->id,
                $latestEventId
            );
        }

        $minRetainedEventId = $this->minRetainedEventId($conversation->id);

        if ($cursor > 0 && ($minRetainedEventId === null || $cursor < $minRetainedEventId)) {
            return $this->resyncRequired(
                WidgetDenyReason::CURSOR_TOO_OLD,
                $conversation->id,
                $latestEventId
            );
        }

        $replayMaxEvents = (int) config('widget.sse.replay_max_events', 500);
        $replayCount = ConversationEvent::where('conversation_id', $conversation->id)
            ->where('id', '>', $cursor)
            ->count();

        if ($replayCount > $replayMaxEvents) {
            return $this->resyncRequired(
                WidgetDenyReason::REPLAY_TOO_LARGE,
                $conversation->id,
                $latestEventId
            );
        }

        // Test mode flag: only allowed in testing/local environments
        // This prevents production memory issues from buffering large payloads
        $once = $request->query('once') === '1'
            && app()->environment(['testing', 'local']);

        $conversationId = $conversation->id;
        $tokenHash = Conversation::hashSessionToken($sessionToken);
        $connectionId = (string) Str::uuid();

        if (!$this->acquireSessionConnection($tokenHash, $connectionId)) {
            Log::warning('SSE denied by session connection cap', [
                'reason_code' => WidgetDenyReason::SSE_SESSION_LIMIT->value,
                'client_id' => $clientId,
                'conversation_id' => $conversationId,
            ]);

            return response()->json([
                'error' => 'TOO_MANY_CONNECTIONS',
                'reason_code' => WidgetDenyReason::SSE_SESSION_LIMIT->value,
                'message' => 'Too many active streams for this session.',
            ], 429);
        }

        // For test mode with once=1, return a regular response with captured content
        // This makes PHPUnit tests deterministic and able to inspect the content
        if ($once) {
            $this->testMode = true;
            $this->outputBuffer = '';

            try {
                $this->sendRetryDirective();
                $this->streamEventsOnce($conversationId, $cursor);
            } finally {
                $this->releaseSessionConnection($tokenHash, $connectionId);
            }

            return response($this->outputBuffer, 200, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache, no-transform',
                'Connection' => 'keep-alive',
                'X-Accel-Buffering' => 'no',
            ]);
        }

        // Production streaming mode
        return new StreamedResponse(
            function () use ($conversationId, $cursor, $tokenHash, $connectionId) {
                try {
                    $this->streamEventsLoop($conversationId, $cursor, $tokenHash, $connectionId);
                } finally {
                    $this->releaseSessionConnection($tokenHash, $connectionId);
                }
            },
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache, no-transform',
                'Connection' => 'keep-alive',
                'X-Accel-Buffering' => 'no',
            ]
        );
    }

    /**
     * Stream events once (for test mode): replay + ping + exit.
     */
    private function streamEventsOnce(string $conversationId, int $cursor): void
    {
        $this->replayEvents($conversationId, $cursor);
        $this->sendPing();
    }

    /**
     * Stream events with keepalive loop (production mode).
     */
    private function streamEventsLoop(string $conversationId, int $cursor, string $tokenHash, string $connectionId): void
    {
        // Prevent mid-write crashes on disconnect
        ignore_user_abort(true);

        // Disable ALL output buffering levels for real-time streaming
        // This is critical for Nginx/PHP-FPM setups
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        // Tell client how long to wait before reconnecting (helps with 60s timeout)
        $this->sendRetryDirective();

        // Replay missed events
        $lastSentId = $this->replayEvents($conversationId, $cursor);

        // Keepalive loop
        $this->keepaliveLoop($conversationId, $lastSentId, $tokenHash, $connectionId);
    }

    /**
     * Replay events from DB since cursor.
     *
     * @return int The last sent event ID
     */
    private function replayEvents(string $conversationId, int $cursor): int
    {
        $replayMaxEvents = (int) config('widget.sse.replay_max_events', 500);

        $events = ConversationEvent::where('conversation_id', $conversationId)
            ->where('id', '>', $cursor)
            ->orderBy('id', 'asc')
            ->limit($replayMaxEvents)
            ->get();

        $lastSentId = $cursor;

        foreach ($events as $event) {
            $this->sendEvent($event);
            $lastSentId = $event->id;
        }

        // Signal that replay is complete (stream-only marker, not stored)
        $this->sendReplayComplete($conversationId, $lastSentId);

        return $lastSentId;
    }

    /**
     * Keepalive loop: ping every 15s, poll for new events, max 60s.
     */
    private function keepaliveLoop(string $conversationId, int $lastSentId, string $tokenHash, string $connectionId): void
    {
        $startTime = time();

        while ((time() - $startTime) < self::MAX_DURATION) {
            // Check if client disconnected
            if (connection_aborted()) {
                break;
            }

            // Send ping
            $this->sendPing();
            $this->refreshSessionConnectionTtl($tokenHash, $connectionId);

            // Poll for new events
            $newEvents = ConversationEvent::where('conversation_id', $conversationId)
                ->where('id', '>', $lastSentId)
                ->orderBy('id', 'asc')
                ->limit(100)
                ->get();

            foreach ($newEvents as $event) {
                $this->sendEvent($event);
                $lastSentId = $event->id;
            }

            // Wait before next poll
            sleep(self::PING_INTERVAL);
        }
    }

    /**
     * Send a single SSE event.
     */
    private function sendEvent(ConversationEvent $event): void
    {
        $envelope = [
            'id' => $event->id,
            'conversation_id' => $event->conversation_id,
            'client_id' => $event->client_id,
            'type' => $event->type->value,
            'payload' => $event->payload,
            'correlation_id' => $event->correlation_id,
            'idempotency_key' => $event->idempotency_key,
            'created_at' => $event->created_at->toIso8601String(),
        ];

        $this->output("id: {$event->id}\n");
        $this->output("event: conversation.event\n");
        $this->output("data: " . json_encode($envelope) . "\n\n");
    }

    /**
     * Send a keepalive ping comment.
     */
    private function sendPing(): void
    {
        $this->output(": ping\n\n");
    }

    /**
     * Send retry directive telling client reconnect delay.
     * This is sent once at the start of the stream.
     */
    private function sendRetryDirective(): void
    {
        $this->output("retry: " . self::RETRY_MS . "\n\n");
    }

    /**
     * Send replay complete signal.
     *
     * This is a stream-only marker (not stored in conversation_events).
     * The widget can treat this as "initial state is now fully reconstructed; we are live".
     */
    private function sendReplayComplete(string $conversationId, int $lastEventId): void
    {
        $this->output("event: conversation.replay.complete\n");
        $this->output("data: " . json_encode([
            'conversation_id' => $conversationId,
            'last_event_id' => $lastEventId,
        ]) . "\n\n");
    }

    /**
     * Output content (buffered in test mode, echoed in production).
     */
    private function output(string $content): void
    {
        if ($this->testMode) {
            $this->outputBuffer .= $content;
        } else {
            echo $content;
            if (ob_get_level()) {
                ob_flush();
            }
            flush();
        }
    }

    /**
     * Return a 401 unauthorized response.
     */
    private function unauthorizedResponse(string $message): JsonResponse
    {
        return response()->json(['error' => $message], 401);
    }

    private function minRetainedEventId(string $conversationId): ?int
    {
        $maxAgeSeconds = (int) config('widget.sse.replay_max_age_seconds', 3600);
        $threshold = Carbon::now()->subSeconds($maxAgeSeconds);

        $id = ConversationEvent::where('conversation_id', $conversationId)
            ->where('created_at', '>=', $threshold)
            ->orderBy('id', 'asc')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    private function resyncRequired(WidgetDenyReason $reason, string $conversationId, int $lastEventId): JsonResponse
    {
        return response()->json([
            'error' => 'RESYNC_REQUIRED',
            'reason_code' => $reason->value,
            'message' => 'Replay cursor is outside replay window. Call /api/widget/history and reconnect.',
            'conversation_id' => $conversationId,
            'last_event_id' => $lastEventId,
        ], 409);
    }

    private function acquireSessionConnection(string $tokenHash, string $connectionId): bool
    {
        $maxConnections = (int) config('widget.sse.max_connections_per_session', 2);
        $setKey = $this->sessionSetKey($tokenHash);
        $connections = Cache::get($setKey, []);

        if (!is_array($connections)) {
            $connections = [];
        }

        $connections = array_values(array_filter($connections, function (string $id) use ($tokenHash) {
            return Cache::has($this->sessionConnKey($tokenHash, $id));
        }));

        if (count($connections) >= $maxConnections) {
            Cache::put($setKey, $connections, $this->connectionTtl() + 10);

            return false;
        }

        $connections[] = $connectionId;
        Cache::put($setKey, array_values(array_unique($connections)), $this->connectionTtl() + 10);
        $this->refreshSessionConnectionTtl($tokenHash, $connectionId);

        return true;
    }

    private function refreshSessionConnectionTtl(string $tokenHash, string $connectionId): void
    {
        Cache::put($this->sessionConnKey($tokenHash, $connectionId), 1, $this->connectionTtl());
    }

    private function releaseSessionConnection(string $tokenHash, string $connectionId): void
    {
        Cache::forget($this->sessionConnKey($tokenHash, $connectionId));
        $setKey = $this->sessionSetKey($tokenHash);
        $connections = Cache::get($setKey, []);

        if (!is_array($connections)) {
            return;
        }

        $connections = array_values(array_filter($connections, fn (string $id) => $id !== $connectionId));
        Cache::put($setKey, $connections, $this->connectionTtl() + 10);
    }

    private function sessionSetKey(string $tokenHash): string
    {
        return "sse:session:{$tokenHash}:conns";
    }

    private function sessionConnKey(string $tokenHash, string $connectionId): string
    {
        return "sse:session:{$tokenHash}:conn:{$connectionId}";
    }

    private function connectionTtl(): int
    {
        return (int) config('widget.sse.connection_ttl_seconds', 60);
    }
}
