<?php

namespace Tests\Feature\Http\Widget;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
