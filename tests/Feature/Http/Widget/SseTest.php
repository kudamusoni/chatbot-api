<?php

namespace Tests\Feature\Http\Widget;

use App\Enums\WidgetDenyReason;
use App\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\InteractsWithConversations;
use Tests\TestCase;

class SseTest extends TestCase
{
    use RefreshDatabase, InteractsWithConversations;

    // =========================================================================
    // Authentication Tests
    // =========================================================================

    public function test_returns_401_for_invalid_token(): void
    {
        $client = $this->makeClient();

        $response = $this->get('/api/widget/sse?' . http_build_query([
            'client_id' => $client->id,
            'session_token' => str_repeat('x', 64),
            'once' => '1',
        ]));

        $response->assertUnauthorized()
            ->assertJson(['error' => 'Invalid session token']);
    }

    public function test_returns_401_for_missing_credentials(): void
    {
        $response = $this->get('/api/widget/sse?once=1');

        $response->assertUnauthorized()
            ->assertJson(['error' => 'Missing client_id or session_token']);
    }

    public function test_returns_401_for_wrong_client(): void
    {
        $clientA = $this->makeClient(['name' => 'Client A']);
        $clientB = $this->makeClient(['name' => 'Client B']);

        [$conversationA, $tokenA] = $this->makeConversation($clientA);

        // Try to use Client A's token with Client B
        $response = $this->get('/api/widget/sse?' . http_build_query([
            'client_id' => $clientB->id,
            'session_token' => $tokenA,
            'once' => '1',
        ]));

        $response->assertUnauthorized();
    }

    // =========================================================================
    // SSE Headers Tests
    // =========================================================================

    public function test_response_has_correct_sse_headers(): void
    {
        $client = $this->makeClient();
        [$conversation, $rawToken] = $this->makeConversation($client);

        $response = $this->get('/api/widget/sse?' . http_build_query([
            'client_id' => $client->id,
            'session_token' => $rawToken,
            'once' => '1',
        ]));

        $response->assertOk();

        // Content-Type may include charset
        $contentType = $response->headers->get('Content-Type');
        $this->assertStringContainsString('text/event-stream', $contentType);

        // Cache-Control may include "private" added by Laravel
        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-cache', $cacheControl);
        $this->assertStringContainsString('no-transform', $cacheControl);

        $response->assertHeader('X-Accel-Buffering', 'no');
    }

    // =========================================================================
    // Event Replay Tests
    // =========================================================================

    public function test_replays_events_after_last_event_id(): void
    {
        $client = $this->makeClient();
        [$conversation, $rawToken] = $this->makeConversation($client);

        // Create some events
        $result1 = $this->recordUserMessage($conversation, 'Hello');
        $result2 = $this->recordAssistantMessage($conversation, 'Hi there!');

        $response = $this->withHeaders([
            'Last-Event-ID' => '0',
        ])->get('/api/widget/sse?' . http_build_query([
            'client_id' => $client->id,
            'session_token' => $rawToken,
            'once' => '1',
        ]));

        $response->assertOk();

        $content = $response->getContent();

        // Should contain both events
        $this->assertStringContainsString("id: {$result1['event']->id}", $content);
        $this->assertStringContainsString("id: {$result2['event']->id}", $content);
        $this->assertStringContainsString('event: conversation.event', $content);
        $this->assertStringContainsString('user.message.created', $content);
        $this->assertStringContainsString('assistant.message.created', $content);

        // Should contain ping
        $this->assertStringContainsString(': ping', $content);
    }

    public function test_after_id_query_param_works(): void
    {
        $client = $this->makeClient();
        [$conversation, $rawToken] = $this->makeConversation($client);

        // Create events
        $result1 = $this->recordUserMessage($conversation, 'First');
        $result2 = $this->recordAssistantMessage($conversation, 'Second');

        // Request events after the first one
        $response = $this->get('/api/widget/sse?' . http_build_query([
            'client_id' => $client->id,
            'session_token' => $rawToken,
            'after_id' => $result1['event']->id,
            'once' => '1',
        ]));

        $response->assertOk();

        $content = $response->getContent();

        // Should NOT contain first event
        $this->assertStringNotContainsString("id: {$result1['event']->id}\n", $content);

        // Should contain second event
        $this->assertStringContainsString("id: {$result2['event']->id}", $content);
    }

    public function test_last_event_id_header_takes_precedence_over_after_id(): void
    {
        $client = $this->makeClient();
        [$conversation, $rawToken] = $this->makeConversation($client);

        // Create events
        $result1 = $this->recordUserMessage($conversation, 'First');
        $result2 = $this->recordAssistantMessage($conversation, 'Second');
        $result3 = $this->recordUserMessage($conversation, 'Third');

        // Last-Event-ID = event 2, after_id = 0 (should use header)
        $response = $this->withHeaders([
            'Last-Event-ID' => (string) $result2['event']->id,
        ])->get('/api/widget/sse?' . http_build_query([
            'client_id' => $client->id,
            'session_token' => $rawToken,
            'after_id' => '0',
            'once' => '1',
        ]));

        $response->assertOk();

        $content = $response->getContent();

        // Should NOT contain events 1 or 2
        $this->assertStringNotContainsString("id: {$result1['event']->id}\n", $content);
        $this->assertStringNotContainsString("id: {$result2['event']->id}\n", $content);

        // Should contain event 3
        $this->assertStringContainsString("id: {$result3['event']->id}", $content);
    }

    // =========================================================================
    // Event Envelope Tests
    // =========================================================================

    public function test_event_envelope_format(): void
    {
        $client = $this->makeClient();
        [$conversation, $rawToken] = $this->makeConversation($client);

        $result = $this->recordUserMessage($conversation, 'Test message');

        $response = $this->get('/api/widget/sse?' . http_build_query([
            'client_id' => $client->id,
            'session_token' => $rawToken,
            'once' => '1',
        ]));

        $response->assertOk();

        $content = $response->getContent();

        // Extract the JSON data line
        preg_match('/data: ({.*})/m', $content, $matches);
        $this->assertNotEmpty($matches, 'Should have data line');

        $envelope = json_decode($matches[1], true);

        // Verify envelope structure (must match admin /events endpoint)
        $this->assertArrayHasKey('id', $envelope);
        $this->assertArrayHasKey('conversation_id', $envelope);
        $this->assertArrayHasKey('client_id', $envelope);
        $this->assertArrayHasKey('type', $envelope);
        $this->assertArrayHasKey('payload', $envelope);
        $this->assertArrayHasKey('correlation_id', $envelope);
        $this->assertArrayHasKey('idempotency_key', $envelope);
        $this->assertArrayHasKey('created_at', $envelope);

        // Verify values
        $this->assertSame($result['event']->id, $envelope['id']);
        $this->assertSame($conversation->id, $envelope['conversation_id']);
        $this->assertSame($client->id, $envelope['client_id']);
        $this->assertSame('user.message.created', $envelope['type']);
        $this->assertSame(['content' => 'Test message'], $envelope['payload']);
    }

    // =========================================================================
    // Header Authentication Tests
    // =========================================================================

    public function test_supports_header_auth(): void
    {
        $client = $this->makeClient();
        [$conversation, $rawToken] = $this->makeConversation($client);

        $this->recordUserMessage($conversation, 'Hello');

        // Use headers instead of query params
        $response = $this->withHeaders([
            'X-Client-Id' => $client->id,
            'X-Session-Token' => $rawToken,
        ])->get('/api/widget/sse?once=1');

        $response->assertOk();

        $content = $response->getContent();
        $this->assertStringContainsString('event: conversation.event', $content);
        $this->assertStringContainsString('user.message.created', $content);
    }

    // =========================================================================
    // Event Ordering Tests
    // =========================================================================

    public function test_events_ordered_by_id_asc(): void
    {
        $client = $this->makeClient();
        [$conversation, $rawToken] = $this->makeConversation($client);

        // Create multiple events
        $result1 = $this->recordUserMessage($conversation, 'First');
        $result2 = $this->recordAssistantMessage($conversation, 'Second');
        $result3 = $this->recordUserMessage($conversation, 'Third');

        $response = $this->get('/api/widget/sse?' . http_build_query([
            'client_id' => $client->id,
            'session_token' => $rawToken,
            'once' => '1',
        ]));

        $response->assertOk();

        $content = $response->getContent();

        // Extract all event IDs in order
        preg_match_all('/^id: (\d+)$/m', $content, $matches);
        $eventIds = array_map('intval', $matches[1]);

        // Verify ascending order
        $this->assertCount(3, $eventIds);
        $this->assertSame($result1['event']->id, $eventIds[0]);
        $this->assertSame($result2['event']->id, $eventIds[1]);
        $this->assertSame($result3['event']->id, $eventIds[2]);

        // Verify strictly increasing
        $this->assertLessThan($eventIds[1], $eventIds[0]);
        $this->assertLessThan($eventIds[2], $eventIds[1]);
    }

    // =========================================================================
    // Test Mode Tests
    // =========================================================================

    public function test_once_mode_exits_after_replay(): void
    {
        $client = $this->makeClient();
        [$conversation, $rawToken] = $this->makeConversation($client);

        $this->recordUserMessage($conversation, 'Hello');

        $response = $this->get('/api/widget/sse?' . http_build_query([
            'client_id' => $client->id,
            'session_token' => $rawToken,
            'once' => '1',
        ]));

        $response->assertOk();

        $content = $response->getContent();

        // Should contain events
        $this->assertStringContainsString('event: conversation.event', $content);

        // Should contain ping (indicates once mode completed)
        $this->assertStringContainsString(': ping', $content);
    }

    public function test_no_events_returns_only_ping(): void
    {
        $client = $this->makeClient();
        [$conversation, $rawToken] = $this->makeConversation($client);

        // No events created

        $response = $this->get('/api/widget/sse?' . http_build_query([
            'client_id' => $client->id,
            'session_token' => $rawToken,
            'once' => '1',
        ]));

        $response->assertOk();

        $content = $response->getContent();

        // Should NOT contain any event
        $this->assertStringNotContainsString('event: conversation.event', $content);

        // Should contain ping
        $this->assertStringContainsString(': ping', $content);
    }

    public function test_includes_retry_directive(): void
    {
        $client = $this->makeClient();
        [$conversation, $rawToken] = $this->makeConversation($client);

        $response = $this->get('/api/widget/sse?' . http_build_query([
            'client_id' => $client->id,
            'session_token' => $rawToken,
            'once' => '1',
        ]));

        $response->assertOk();

        $content = $response->getContent();

        // Should contain retry directive (2000ms)
        $this->assertStringContainsString('retry: 2000', $content);
    }

    // =========================================================================
    // Replay Complete Signal Tests
    // =========================================================================

    public function test_emits_replay_complete_after_replay(): void
    {
        $client = $this->makeClient();
        [$conversation, $rawToken] = $this->makeConversation($client);

        // Create some events
        $result1 = $this->recordUserMessage($conversation, 'Hello');
        $result2 = $this->recordAssistantMessage($conversation, 'Hi there!');

        $response = $this->get('/api/widget/sse?' . http_build_query([
            'client_id' => $client->id,
            'session_token' => $rawToken,
            'once' => '1',
        ]));

        $response->assertOk();

        $content = $response->getContent();

        // Should contain replay complete event
        $this->assertStringContainsString('event: conversation.replay.complete', $content);

        // Extract replay complete data
        preg_match('/event: conversation\.replay\.complete\ndata: ({.*})/m', $content, $matches);
        $this->assertNotEmpty($matches, 'Should have replay complete data');

        $replayComplete = json_decode($matches[1], true);

        // Verify replay complete structure
        $this->assertArrayHasKey('conversation_id', $replayComplete);
        $this->assertArrayHasKey('last_event_id', $replayComplete);
        $this->assertSame($conversation->id, $replayComplete['conversation_id']);
        $this->assertSame($result2['event']->id, $replayComplete['last_event_id']);
    }

    public function test_replay_complete_emitted_even_with_no_events(): void
    {
        $client = $this->makeClient();
        [$conversation, $rawToken] = $this->makeConversation($client);

        // No events created

        $response = $this->get('/api/widget/sse?' . http_build_query([
            'client_id' => $client->id,
            'session_token' => $rawToken,
            'once' => '1',
        ]));

        $response->assertOk();

        $content = $response->getContent();

        // Should still contain replay complete event
        $this->assertStringContainsString('event: conversation.replay.complete', $content);

        // Extract replay complete data
        preg_match('/event: conversation\.replay\.complete\ndata: ({.*})/m', $content, $matches);
        $this->assertNotEmpty($matches, 'Should have replay complete data');

        $replayComplete = json_decode($matches[1], true);

        // last_event_id should be 0 (cursor default)
        $this->assertSame(0, $replayComplete['last_event_id']);
    }

    public function test_replay_complete_reflects_cursor_position(): void
    {
        $client = $this->makeClient();
        [$conversation, $rawToken] = $this->makeConversation($client);

        // Create events
        $result1 = $this->recordUserMessage($conversation, 'First');
        $result2 = $this->recordAssistantMessage($conversation, 'Second');
        $result3 = $this->recordUserMessage($conversation, 'Third');

        // Request events after the second one
        $response = $this->get('/api/widget/sse?' . http_build_query([
            'client_id' => $client->id,
            'session_token' => $rawToken,
            'after_id' => $result2['event']->id,
            'once' => '1',
        ]));

        $response->assertOk();

        $content = $response->getContent();

        // Extract replay complete data
        preg_match('/event: conversation\.replay\.complete\ndata: ({.*})/m', $content, $matches);
        $replayComplete = json_decode($matches[1], true);

        // last_event_id should be the third event (last replayed)
        $this->assertSame($result3['event']->id, $replayComplete['last_event_id']);
    }

    public function test_returns_409_when_cursor_ahead_of_latest(): void
    {
        $client = $this->makeClient();
        [$conversation, $rawToken] = $this->makeConversation($client);
        $this->recordUserMessage($conversation, 'Hello');
        $latestId = (int) $conversation->events()->max('id');

        $response = $this->get('/api/widget/sse?' . http_build_query([
            'client_id' => $client->id,
            'session_token' => $rawToken,
            'after_id' => $latestId + 10,
            'once' => '1',
        ]));

        $response->assertStatus(409)
            ->assertJson([
                'error' => 'RESYNC_REQUIRED',
                'reason_code' => WidgetDenyReason::CURSOR_AHEAD_OF_LATEST->value,
            ]);
    }

    public function test_returns_409_when_replay_is_too_large(): void
    {
        config()->set('widget.sse.replay_max_events', 2);
        config()->set('widget.sse.replay_max_age_seconds', 3600);

        $client = $this->makeClient();
        [$conversation, $rawToken] = $this->makeConversation($client);
        $this->recordUserMessage($conversation, '1');
        $this->recordAssistantMessage($conversation, '2');
        $this->recordUserMessage($conversation, '3');

        $response = $this->get('/api/widget/sse?' . http_build_query([
            'client_id' => $client->id,
            'session_token' => $rawToken,
            'after_id' => 0,
            'once' => '1',
        ]));

        $response->assertStatus(409)
            ->assertJson([
                'error' => 'RESYNC_REQUIRED',
                'reason_code' => WidgetDenyReason::REPLAY_TOO_LARGE->value,
            ]);
    }

    public function test_returns_409_when_cursor_is_too_old_for_retention_window(): void
    {
        config()->set('widget.sse.replay_max_events', 500);
        config()->set('widget.sse.replay_max_age_seconds', 3600);

        $client = $this->makeClient();
        [$conversation, $rawToken] = $this->makeConversation($client);

        Carbon::setTestNow(now()->subHours(2));
        $oldEvent = $this->recordUserMessage($conversation, 'old')['event'];

        Carbon::setTestNow(now());
        $this->recordAssistantMessage($conversation, 'recent');
        Carbon::setTestNow();

        $response = $this->get('/api/widget/sse?' . http_build_query([
            'client_id' => $client->id,
            'session_token' => $rawToken,
            'after_id' => $oldEvent->id,
            'once' => '1',
        ]));

        $response->assertStatus(409)
            ->assertJson([
                'error' => 'RESYNC_REQUIRED',
                'reason_code' => WidgetDenyReason::CURSOR_TOO_OLD->value,
            ]);
    }

    public function test_returns_429_when_session_connection_limit_is_reached(): void
    {
        config()->set('widget.sse.max_connections_per_session', 1);

        $client = $this->makeClient();
        [$conversation, $rawToken] = $this->makeConversation($client);

        $tokenHash = Conversation::hashSessionToken($rawToken);
        Cache::put("sse:session:{$tokenHash}:conns", ['existing'], 70);
        Cache::put("sse:session:{$tokenHash}:conn:existing", 1, 60);

        $response = $this->get('/api/widget/sse?' . http_build_query([
            'client_id' => $client->id,
            'session_token' => $rawToken,
            'once' => '1',
        ]));

        $response->assertStatus(429)
            ->assertJson([
                'error' => 'TOO_MANY_CONNECTIONS',
                'reason_code' => WidgetDenyReason::SSE_SESSION_LIMIT->value,
            ]);
    }
}
