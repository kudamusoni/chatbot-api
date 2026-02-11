<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SseController extends Controller
{
    /**
     * Maximum events to replay on reconnect.
     */
    private const REPLAY_LIMIT = 500;

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
        $cursor = (int) ($request->header('Last-Event-ID')
            ?? $request->query('after_id')
            ?? 0);

        // Test mode flag: only allowed in testing/local environments
        // This prevents production memory issues from buffering large payloads
        $once = $request->query('once') === '1'
            && app()->environment(['testing', 'local']);

        $conversationId = $conversation->id;

        // For test mode with once=1, return a regular response with captured content
        // This makes PHPUnit tests deterministic and able to inspect the content
        if ($once) {
            $this->testMode = true;
            $this->outputBuffer = '';

            $this->sendRetryDirective();
            $this->streamEventsOnce($conversationId, $cursor);

            return response($this->outputBuffer, 200, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache, no-transform',
                'Connection' => 'keep-alive',
                'X-Accel-Buffering' => 'no',
            ]);
        }

        // Production streaming mode
        return new StreamedResponse(
            function () use ($conversationId, $cursor) {
                $this->streamEventsLoop($conversationId, $cursor);
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
    private function streamEventsLoop(string $conversationId, int $cursor): void
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
        $this->keepaliveLoop($conversationId, $lastSentId);
    }

    /**
     * Replay events from DB since cursor.
     *
     * @return int The last sent event ID
     */
    private function replayEvents(string $conversationId, int $cursor): int
    {
        // Query LIMIT + 1 to detect overflow
        $events = ConversationEvent::where('conversation_id', $conversationId)
            ->where('id', '>', $cursor)
            ->orderBy('id', 'asc')
            ->limit(self::REPLAY_LIMIT + 1)
            ->get();

        $hasMore = $events->count() > self::REPLAY_LIMIT;
        $eventsToSend = $events->take(self::REPLAY_LIMIT);

        $lastSentId = $cursor;

        foreach ($eventsToSend as $event) {
            $this->sendEvent($event);
            $lastSentId = $event->id;
        }

        // Emit error if replay limit exceeded
        if ($hasMore) {
            $this->sendReplayLimitError($lastSentId);
        }

        // Signal that replay is complete (stream-only marker, not stored)
        $this->sendReplayComplete($conversationId, $lastSentId);

        return $lastSentId;
    }

    /**
     * Keepalive loop: ping every 15s, poll for new events, max 60s.
     */
    private function keepaliveLoop(string $conversationId, int $lastSentId): void
    {
        $startTime = time();

        while ((time() - $startTime) < self::MAX_DURATION) {
            // Check if client disconnected
            if (connection_aborted()) {
                break;
            }

            // Send ping
            $this->sendPing();

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
     * Send replay limit error event.
     */
    private function sendReplayLimitError(int $lastSentId): void
    {
        $this->output("event: conversation.error\n");
        $this->output("data: " . json_encode([
            'code' => 'REPLAY_LIMIT',
            'message' => 'Too many events to replay. Reconnect with new cursor.',
            'last_sent_id' => $lastSentId,
        ]) . "\n\n");
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
}
