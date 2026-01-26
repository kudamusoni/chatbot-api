<?php

namespace Tests\Feature\Domain;

use App\Models\Client;
use App\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithConversations;
use Tests\TestCase;

/**
 * Tests for conversation token invariants.
 * These prevent tenant/session bugs.
 */
class ConversationTokenTest extends TestCase
{
    use RefreshDatabase, InteractsWithConversations;

    public function test_create_with_token_stores_hash_and_returns_raw_token(): void
    {
        $client = $this->makeClient();

        [$conversation, $rawToken] = Conversation::createWithToken($client->id);

        // Raw token should be 64 characters
        $this->assertIsString($rawToken);
        $this->assertEquals(64, strlen($rawToken));

        // Conversation should have a hash stored
        $this->assertNotNull($conversation->session_token_hash);
        $this->assertEquals(64, strlen($conversation->session_token_hash)); // SHA-256 hex

        // Hash should NOT equal raw token
        $this->assertNotEquals($rawToken, $conversation->session_token_hash);

        // Hash should match what we'd compute from raw token
        $expectedHash = Conversation::hashSessionToken($rawToken);
        $this->assertEquals($expectedHash, $conversation->session_token_hash);
    }

    public function test_raw_token_is_never_stored_in_database(): void
    {
        $client = $this->makeClient();

        [$conversation, $rawToken] = Conversation::createWithToken($client->id);

        // Refresh from DB to ensure we're checking persisted data
        $conversation->refresh();

        // The raw token should not appear anywhere in the conversation record
        $conversationArray = $conversation->toArray();
        $conversationJson = json_encode($conversationArray);

        $this->assertStringNotContainsString($rawToken, $conversationJson);

        // Double-check: raw token should not be in any column
        $this->assertNotEquals($rawToken, $conversation->session_token_hash);
        $this->assertNotEquals($rawToken, $conversation->id);
    }

    public function test_find_by_token_for_client_returns_conversation_for_valid_token(): void
    {
        $client = $this->makeClient();

        [$conversation, $rawToken] = Conversation::createWithToken($client->id);

        // Should find conversation with raw token and client scope
        $found = Conversation::findByTokenForClient($rawToken, $client->id);

        $this->assertNotNull($found);
        $this->assertEquals($conversation->id, $found->id);
    }

    public function test_find_by_token_for_client_returns_null_for_invalid_token(): void
    {
        $client = $this->makeClient();

        Conversation::createWithToken($client->id);

        // Random token should not find anything
        $found = Conversation::findByTokenForClient('invalid-token-that-does-not-exist', $client->id);

        $this->assertNull($found);
    }

    public function test_find_by_token_for_client_enforces_tenant_isolation(): void
    {
        $clientA = $this->makeClient(['name' => 'Client A', 'slug' => 'client-a']);
        $clientB = $this->makeClient(['name' => 'Client B', 'slug' => 'client-b']);

        [$conversationA, $tokenA] = Conversation::createWithToken($clientA->id);

        // Token A should work for Client A
        $foundForA = Conversation::findByTokenForClient($tokenA, $clientA->id);
        $this->assertNotNull($foundForA);
        $this->assertEquals($conversationA->id, $foundForA->id);

        // Token A should NOT work for Client B (tenant isolation)
        $foundForB = Conversation::findByTokenForClient($tokenA, $clientB->id);
        $this->assertNull($foundForB);
    }

    public function test_tokens_are_unique_per_client(): void
    {
        $client = $this->makeClient();

        // Create multiple conversations
        [$conv1, $token1] = Conversation::createWithToken($client->id);
        [$conv2, $token2] = Conversation::createWithToken($client->id);

        // Tokens should be different
        $this->assertNotEquals($token1, $token2);

        // Hashes should be different
        $this->assertNotEquals($conv1->session_token_hash, $conv2->session_token_hash);
    }

    public function test_same_token_hash_can_exist_for_different_clients(): void
    {
        // This tests the unique(client_id, session_token_hash) constraint
        // allows the same hash across different clients (edge case)

        $clientA = $this->makeClient(['name' => 'Client A', 'slug' => 'client-a-' . uniqid()]);
        $clientB = $this->makeClient(['name' => 'Client B', 'slug' => 'client-b-' . uniqid()]);

        // Manually create with same hash (simulating collision, which is astronomically unlikely)
        $hash = Conversation::hashSessionToken('test-token');

        $convA = Conversation::create([
            'client_id' => $clientA->id,
            'session_token_hash' => $hash,
        ]);

        // Should be able to create same hash for different client
        $convB = Conversation::create([
            'client_id' => $clientB->id,
            'session_token_hash' => $hash,
        ]);

        $this->assertNotEquals($convA->id, $convB->id);
        $this->assertEquals($convA->session_token_hash, $convB->session_token_hash);
    }

    public function test_duplicate_hash_for_same_client_is_rejected(): void
    {
        $client = $this->makeClient();

        $hash = Conversation::hashSessionToken('test-token');

        Conversation::create([
            'client_id' => $client->id,
            'session_token_hash' => $hash,
        ]);

        // Second conversation with same hash for same client should fail
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        Conversation::create([
            'client_id' => $client->id,
            'session_token_hash' => $hash,
        ]);
    }
}
