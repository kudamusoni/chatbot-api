<?php

namespace Tests\Feature\Http\Widget;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithConversations;
use Tests\TestCase;

class BootstrapTest extends TestCase
{
    use RefreshDatabase, InteractsWithConversations;

    public function test_creates_conversation_when_no_token_provided(): void
    {
        $client = $this->makeClient();

        $response = $this->postJson('/api/widget/bootstrap', [
            'client_id' => $client->id,
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'session_token',
                'conversation_id',
                'last_event_id',
                'last_activity_at',
            ]);

        // Token should be 64 characters
        $this->assertSame(64, strlen($response->json('session_token')));

        // Conversation should exist in database
        $this->assertDatabaseHas('conversations', [
            'id' => $response->json('conversation_id'),
            'client_id' => $client->id,
        ]);
    }

    public function test_resumes_conversation_when_valid_token_provided(): void
    {
        $client = $this->makeClient();
        [$conversation, $rawToken] = $this->makeConversation($client);

        $response = $this->postJson('/api/widget/bootstrap', [
            'client_id' => $client->id,
            'session_token' => $rawToken,
        ]);

        $response->assertOk();

        // Should return the same conversation
        $this->assertSame($conversation->id, $response->json('conversation_id'));

        // Should return the same token
        $this->assertSame($rawToken, $response->json('session_token'));
    }

    public function test_token_for_client_a_does_not_work_for_client_b(): void
    {
        $clientA = $this->makeClient(['name' => 'Client A']);
        $clientB = $this->makeClient(['name' => 'Client B']);

        [$conversationA, $tokenA] = $this->makeConversation($clientA);

        // Try to use Client A's token with Client B
        $response = $this->postJson('/api/widget/bootstrap', [
            'client_id' => $clientB->id,
            'session_token' => $tokenA,
        ]);

        $response->assertOk();

        // Should create a NEW conversation, not resume Client A's
        $this->assertNotSame($conversationA->id, $response->json('conversation_id'));

        // New conversation should belong to Client B
        $this->assertDatabaseHas('conversations', [
            'id' => $response->json('conversation_id'),
            'client_id' => $clientB->id,
        ]);

        // Should return a NEW token, not the old one
        $this->assertNotSame($tokenA, $response->json('session_token'));
    }

    public function test_creates_new_conversation_when_invalid_token_provided(): void
    {
        $client = $this->makeClient();
        $invalidToken = str_repeat('x', 64);

        $response = $this->postJson('/api/widget/bootstrap', [
            'client_id' => $client->id,
            'session_token' => $invalidToken,
        ]);

        $response->assertOk();

        // Should create a new conversation
        $this->assertDatabaseHas('conversations', [
            'id' => $response->json('conversation_id'),
            'client_id' => $client->id,
        ]);

        // Should return a NEW valid token, not the invalid one
        $this->assertNotSame($invalidToken, $response->json('session_token'));
        $this->assertSame(64, strlen($response->json('session_token')));
    }

    public function test_returns_422_for_invalid_client_id(): void
    {
        $response = $this->postJson('/api/widget/bootstrap', [
            'client_id' => '00000000-0000-0000-0000-000000000000',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['client_id']);
    }

    public function test_returns_422_for_missing_client_id(): void
    {
        $response = $this->postJson('/api/widget/bootstrap', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['client_id']);
    }

    public function test_returns_last_event_id_and_last_activity_at(): void
    {
        $client = $this->makeClient();
        [$conversation, $rawToken] = $this->makeConversation($client);

        // Record some events to update last_event_id
        $this->recordUserMessage($conversation, 'Hello');
        $conversation->refresh();

        $response = $this->postJson('/api/widget/bootstrap', [
            'client_id' => $client->id,
            'session_token' => $rawToken,
        ]);

        $response->assertOk();

        // last_event_id should be > 0 after recording events
        $this->assertGreaterThan(0, $response->json('last_event_id'));
        $this->assertNotNull($response->json('last_activity_at'));
    }

    public function test_token_never_stored_raw_in_database(): void
    {
        $client = $this->makeClient();

        $response = $this->postJson('/api/widget/bootstrap', [
            'client_id' => $client->id,
        ]);

        $response->assertOk();

        $rawToken = $response->json('session_token');
        $conversationId = $response->json('conversation_id');

        // Raw token should NOT be stored anywhere in conversations table
        $this->assertDatabaseMissing('conversations', [
            'id' => $conversationId,
            'session_token_hash' => $rawToken,
        ]);

        // Verify the hash is what's stored
        $expectedHash = hash('sha256', $rawToken);
        $this->assertDatabaseHas('conversations', [
            'id' => $conversationId,
            'session_token_hash' => $expectedHash,
        ]);
    }
}
