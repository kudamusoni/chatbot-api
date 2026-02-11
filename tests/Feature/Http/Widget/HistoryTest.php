<?php

namespace Tests\Feature\Http\Widget;

use App\Enums\ConversationState;
use App\Enums\ValuationStatus;
use App\Models\Valuation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithConversations;
use Tests\TestCase;

class HistoryTest extends TestCase
{
    use RefreshDatabase, InteractsWithConversations;

    // =========================================================================
    // Authentication Tests
    // =========================================================================

    public function test_returns_401_for_invalid_token(): void
    {
        $client = $this->makeClient();

        $response = $this->getJson('/api/widget/history?' . http_build_query([
            'client_id' => $client->id,
            'session_token' => str_repeat('x', 64),
        ]));

        $response->assertUnauthorized()
            ->assertJson(['error' => 'Invalid session token']);
    }

    public function test_returns_422_for_missing_credentials(): void
    {
        $response = $this->getJson('/api/widget/history');

        $response->assertUnprocessable();
    }

    public function test_returns_401_for_wrong_client(): void
    {
        $clientA = $this->makeClient(['name' => 'Client A']);
        $clientB = $this->makeClient(['name' => 'Client B']);

        [$conversationA, $tokenA] = $this->makeConversation($clientA);

        // Try to use Client A's token with Client B
        $response = $this->getJson('/api/widget/history?' . http_build_query([
            'client_id' => $clientB->id,
            'session_token' => $tokenA,
        ]));

        $response->assertUnauthorized();
    }

    // =========================================================================
    // Basic Response Tests
    // =========================================================================

    public function test_returns_empty_messages_for_new_conversation(): void
    {
        $client = $this->makeClient();
        [$conversation, $rawToken] = $this->makeConversation($client);

        $response = $this->getJson('/api/widget/history?' . http_build_query([
            'client_id' => $client->id,
            'session_token' => $rawToken,
        ]));

        $response->assertOk()
            ->assertJson([
                'conversation_id' => $conversation->id,
                'last_event_id' => 0,
                'messages' => [],
                'state' => 'CHAT',
                'appraisal_current_key' => null,
                'appraisal_snapshot' => null,
                'valuation' => null,
            ]);
    }

    public function test_returns_messages_in_order(): void
    {
        $client = $this->makeClient();
        [$conversation, $rawToken] = $this->makeConversation($client);

        // Create messages
        $result1 = $this->recordUserMessage($conversation, 'Hello');
        $result2 = $this->recordAssistantMessage($conversation, 'Hi there!');
        $result3 = $this->recordUserMessage($conversation, 'How are you?');

        $response = $this->getJson('/api/widget/history?' . http_build_query([
            'client_id' => $client->id,
            'session_token' => $rawToken,
        ]));

        $response->assertOk();

        $data = $response->json();

        $this->assertCount(3, $data['messages']);

        // Verify order (by event_id ascending)
        $this->assertSame('user', $data['messages'][0]['role']);
        $this->assertSame('Hello', $data['messages'][0]['content']);
        $this->assertSame($result1['event']->id, $data['messages'][0]['event_id']);

        $this->assertSame('assistant', $data['messages'][1]['role']);
        $this->assertSame('Hi there!', $data['messages'][1]['content']);

        $this->assertSame('user', $data['messages'][2]['role']);
        $this->assertSame('How are you?', $data['messages'][2]['content']);
    }

    public function test_respects_limit_parameter(): void
    {
        $client = $this->makeClient();
        [$conversation, $rawToken] = $this->makeConversation($client);

        // Create many messages
        for ($i = 1; $i <= 10; $i++) {
            $this->recordUserMessage($conversation, "Message {$i}");
        }

        $response = $this->getJson('/api/widget/history?' . http_build_query([
            'client_id' => $client->id,
            'session_token' => $rawToken,
            'limit' => 5,
        ]));

        $response->assertOk();

        $data = $response->json();

        // Should only return first 5 messages
        $this->assertCount(5, $data['messages']);
        $this->assertSame('Message 1', $data['messages'][0]['content']);
        $this->assertSame('Message 5', $data['messages'][4]['content']);
    }

    public function test_validates_limit_max(): void
    {
        $client = $this->makeClient();
        [$conversation, $rawToken] = $this->makeConversation($client);

        $response = $this->getJson('/api/widget/history?' . http_build_query([
            'client_id' => $client->id,
            'session_token' => $rawToken,
            'limit' => 1000, // Over max
        ]));

        $response->assertUnprocessable();
    }

    // =========================================================================
    // State & Projection Fields Tests
    // =========================================================================

    public function test_returns_chat_state_with_null_projections(): void
    {
        $client = $this->makeClient();
        [$conversation, $rawToken] = $this->makeConversation($client);

        $response = $this->getJson('/api/widget/history?' . http_build_query([
            'client_id' => $client->id,
            'session_token' => $rawToken,
        ]));

        $response->assertOk()
            ->assertJson([
                'state' => 'CHAT',
                'appraisal_current_key' => null,
                'appraisal_snapshot' => null,
                'valuation' => null,
            ]);
    }

    public function test_returns_appraisal_intake_state_with_current_key(): void
    {
        $client = $this->makeClient();
        [$conversation, $rawToken] = $this->makeConversation($client, [
            'state' => ConversationState::APPRAISAL_INTAKE,
            'appraisal_current_key' => 'make',
        ]);

        $response = $this->getJson('/api/widget/history?' . http_build_query([
            'client_id' => $client->id,
            'session_token' => $rawToken,
        ]));

        $response->assertOk()
            ->assertJson([
                'state' => 'APPRAISAL_INTAKE',
                'appraisal_current_key' => 'make',
            ]);
    }

    public function test_returns_appraisal_confirm_state_with_snapshot(): void
    {
        $client = $this->makeClient();
        $snapshot = ['make' => 'Toyota', 'model' => 'Camry', 'year' => 2020];
        [$conversation, $rawToken] = $this->makeConversation($client, [
            'state' => ConversationState::APPRAISAL_CONFIRM,
            'appraisal_snapshot' => $snapshot,
        ]);

        $response = $this->getJson('/api/widget/history?' . http_build_query([
            'client_id' => $client->id,
            'session_token' => $rawToken,
        ]));

        $response->assertOk()
            ->assertJson([
                'state' => 'APPRAISAL_CONFIRM',
                'appraisal_snapshot' => $snapshot,
            ]);
    }

    public function test_returns_valuation_running_state(): void
    {
        $client = $this->makeClient();
        [$conversation, $rawToken] = $this->makeConversation($client, [
            'state' => ConversationState::VALUATION_RUNNING,
        ]);

        $response = $this->getJson('/api/widget/history?' . http_build_query([
            'client_id' => $client->id,
            'session_token' => $rawToken,
        ]));

        $response->assertOk()
            ->assertJson([
                'state' => 'VALUATION_RUNNING',
            ]);
    }

    public function test_returns_valuation_ready_state_with_result(): void
    {
        $client = $this->makeClient();
        [$conversation, $rawToken] = $this->makeConversation($client, [
            'state' => ConversationState::VALUATION_READY,
        ]);

        // Create completed valuation
        Valuation::create([
            'conversation_id' => $conversation->id,
            'client_id' => $client->id,
            'status' => ValuationStatus::COMPLETED,
            'snapshot_hash' => 'test-hash',
            'input_snapshot' => ['make' => 'Toyota'],
            'result' => ['value' => 25000, 'currency' => 'USD'],
        ]);

        $response = $this->getJson('/api/widget/history?' . http_build_query([
            'client_id' => $client->id,
            'session_token' => $rawToken,
        ]));

        $response->assertOk()
            ->assertJson([
                'state' => 'VALUATION_READY',
                'valuation' => [
                    'status' => 'COMPLETED',
                    'result' => ['value' => 25000, 'currency' => 'USD'],
                ],
            ]);
    }

    public function test_returns_valuation_failed_state_with_error(): void
    {
        $client = $this->makeClient();
        [$conversation, $rawToken] = $this->makeConversation($client, [
            'state' => ConversationState::VALUATION_FAILED,
        ]);

        // Create failed valuation
        Valuation::create([
            'conversation_id' => $conversation->id,
            'client_id' => $client->id,
            'status' => ValuationStatus::FAILED,
            'snapshot_hash' => 'test-hash',
            'input_snapshot' => ['make' => 'Toyota'],
            'result' => ['error' => 'Service unavailable'],
        ]);

        $response = $this->getJson('/api/widget/history?' . http_build_query([
            'client_id' => $client->id,
            'session_token' => $rawToken,
        ]));

        $response->assertOk()
            ->assertJson([
                'state' => 'VALUATION_FAILED',
                'valuation' => [
                    'status' => 'FAILED',
                    'result' => ['error' => 'Service unavailable'],
                ],
            ]);
    }

    // =========================================================================
    // Message Structure Tests
    // =========================================================================

    public function test_message_structure_contains_required_fields(): void
    {
        $client = $this->makeClient();
        [$conversation, $rawToken] = $this->makeConversation($client);

        $result = $this->recordUserMessage($conversation, 'Test message');

        $response = $this->getJson('/api/widget/history?' . http_build_query([
            'client_id' => $client->id,
            'session_token' => $rawToken,
        ]));

        $response->assertOk();

        $data = $response->json();
        $message = $data['messages'][0];

        $this->assertArrayHasKey('event_id', $message);
        $this->assertArrayHasKey('role', $message);
        $this->assertArrayHasKey('content', $message);
        $this->assertArrayHasKey('created_at', $message);

        $this->assertSame($result['event']->id, $message['event_id']);
        $this->assertSame('user', $message['role']);
        $this->assertSame('Test message', $message['content']);
    }

    // =========================================================================
    // Last Event ID Tests
    // =========================================================================

    public function test_returns_correct_last_event_id(): void
    {
        $client = $this->makeClient();
        [$conversation, $rawToken] = $this->makeConversation($client);

        // Create messages
        $this->recordUserMessage($conversation, 'First');
        $this->recordAssistantMessage($conversation, 'Second');
        $result3 = $this->recordUserMessage($conversation, 'Third');

        // Refresh conversation to get updated last_event_id
        $conversation->refresh();

        $response = $this->getJson('/api/widget/history?' . http_build_query([
            'client_id' => $client->id,
            'session_token' => $rawToken,
        ]));

        $response->assertOk();

        $data = $response->json();

        // last_event_id should match the conversation's tracked value
        $this->assertSame($conversation->last_event_id, $data['last_event_id']);
    }
}
