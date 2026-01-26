<?php

namespace Tests\Feature\Http\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\InteractsWithConversations;
use Tests\TestCase;

class ConversationAccessTest extends TestCase
{
    use RefreshDatabase, InteractsWithConversations;

    private function createAuthenticatedUser(array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Test Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password123'),
        ], $attributes));
    }

    public function test_can_only_see_clients_user_has_access_to(): void
    {
        $user = $this->createAuthenticatedUser();

        $clientA = $this->makeClient(['name' => 'Client A']);
        $clientB = $this->makeClient(['name' => 'Client B']);
        $clientC = $this->makeClient(['name' => 'Client C']);

        // User has access to A and B, but not C
        $user->clients()->attach($clientA->id, ['role' => 'admin']);
        $user->clients()->attach($clientB->id, ['role' => 'member']);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/clients');

        $response->assertOk();

        $clientIds = collect($response->json('clients'))->pluck('id')->toArray();

        $this->assertContains($clientA->id, $clientIds);
        $this->assertContains($clientB->id, $clientIds);
        $this->assertNotContains($clientC->id, $clientIds);
    }

    public function test_cannot_fetch_conversations_for_unauthorized_client(): void
    {
        $user = $this->createAuthenticatedUser();

        $clientA = $this->makeClient(['name' => 'Client A']);
        $clientB = $this->makeClient(['name' => 'Client B']);

        // User only has access to Client A
        $user->clients()->attach($clientA->id, ['role' => 'admin']);

        // Try to fetch Client B's conversations
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/conversations?client_id=' . $clientB->id);

        $response->assertForbidden()
            ->assertJson(['error' => 'Access denied to this client']);
    }

    public function test_can_fetch_conversations_for_authorized_client(): void
    {
        $user = $this->createAuthenticatedUser();

        $client = $this->makeClient();
        $user->clients()->attach($client->id, ['role' => 'admin']);

        [$conversation1, $token1] = $this->makeConversation($client);
        [$conversation2, $token2] = $this->makeConversation($client);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/conversations?client_id=' . $client->id);

        $response->assertOk()
            ->assertJsonStructure([
                'conversations',
                'pagination',
            ]);

        $conversationIds = collect($response->json('conversations'))->pluck('id')->toArray();

        $this->assertContains($conversation1->id, $conversationIds);
        $this->assertContains($conversation2->id, $conversationIds);
    }

    public function test_cannot_fetch_messages_for_unauthorized_conversation(): void
    {
        $user = $this->createAuthenticatedUser();

        $clientA = $this->makeClient(['name' => 'Client A']);
        $clientB = $this->makeClient(['name' => 'Client B']);

        // User only has access to Client A
        $user->clients()->attach($clientA->id, ['role' => 'admin']);

        // Create conversation in Client B
        [$conversationB, $tokenB] = $this->makeConversation($clientB);

        // Try to fetch Client B's conversation messages
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/conversations/' . $conversationB->id . '/messages');

        $response->assertForbidden()
            ->assertJson(['error' => 'Access denied to this conversation']);
    }

    public function test_can_fetch_messages_for_authorized_conversation(): void
    {
        $user = $this->createAuthenticatedUser();

        $client = $this->makeClient();
        $user->clients()->attach($client->id, ['role' => 'admin']);

        [$conversation, $token] = $this->makeConversation($client);

        // Add some messages
        $this->recordUserMessage($conversation, 'Hello');
        $this->recordAssistantMessage($conversation, 'Hi there!');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/conversations/' . $conversation->id . '/messages');

        $response->assertOk()
            ->assertJsonStructure([
                'conversation_id',
                'messages' => [
                    '*' => ['id', 'event_id', 'role', 'content', 'created_at'],
                ],
            ]);

        $messages = $response->json('messages');
        $this->assertCount(2, $messages);

        // Check messages are ordered by event_id
        $eventIds = collect($messages)->pluck('event_id')->toArray();
        $this->assertSame($eventIds, collect($eventIds)->sort()->values()->toArray());
    }

    public function test_conversations_ordered_by_last_activity(): void
    {
        $user = $this->createAuthenticatedUser();

        $client = $this->makeClient();
        $user->clients()->attach($client->id, ['role' => 'admin']);

        [$conversation1, $token1] = $this->makeConversation($client);
        [$conversation2, $token2] = $this->makeConversation($client);

        // Add activity to conversation1 (making it more recent)
        $this->recordUserMessage($conversation1, 'Hello');
        $conversation1->refresh();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/conversations?client_id=' . $client->id);

        $response->assertOk();

        $conversations = $response->json('conversations');

        // conversation1 should be first (most recent activity)
        $this->assertSame($conversation1->id, $conversations[0]['id']);
    }

    public function test_requires_authentication(): void
    {
        $client = $this->makeClient();

        // Try to access without authentication
        $this->getJson('/api/admin/clients')
            ->assertUnauthorized();

        $this->getJson('/api/admin/conversations?client_id=' . $client->id)
            ->assertUnauthorized();
    }

    public function test_conversations_endpoint_requires_client_id(): void
    {
        $user = $this->createAuthenticatedUser();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/conversations');

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['client_id']);
    }

    public function test_returns_role_with_clients(): void
    {
        $user = $this->createAuthenticatedUser();

        $clientA = $this->makeClient(['name' => 'Client A']);
        $clientB = $this->makeClient(['name' => 'Client B']);

        $user->clients()->attach($clientA->id, ['role' => 'admin']);
        $user->clients()->attach($clientB->id, ['role' => 'member']);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/clients');

        $response->assertOk();

        $clients = collect($response->json('clients'));

        $clientAData = $clients->firstWhere('id', $clientA->id);
        $clientBData = $clients->firstWhere('id', $clientB->id);

        $this->assertSame('admin', $clientAData['role']);
        $this->assertSame('member', $clientBData['role']);
    }

    // =========================================================================
    // Events Debug Endpoint Tests
    // =========================================================================

    public function test_can_fetch_events_for_authorized_conversation(): void
    {
        $user = $this->createAuthenticatedUser();

        $client = $this->makeClient();
        $user->clients()->attach($client->id, ['role' => 'admin']);

        [$conversation, $token] = $this->makeConversation($client);

        // Add some events
        $this->recordUserMessage($conversation, 'Hello');
        $this->recordAssistantMessage($conversation, 'Hi there!');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/conversations/' . $conversation->id . '/events');

        $response->assertOk()
            ->assertJsonStructure([
                'conversation_id',
                'events' => [
                    '*' => ['id', 'type', 'payload', 'correlation_id', 'created_at'],
                ],
                'has_more',
            ]);

        $events = $response->json('events');
        $this->assertCount(2, $events);

        // Events should be ordered by id
        $this->assertLessThan($events[1]['id'], $events[0]['id']);

        // Check event types
        $this->assertSame('user.message.created', $events[0]['type']);
        $this->assertSame('assistant.message.created', $events[1]['type']);
    }

    public function test_cannot_fetch_events_for_unauthorized_conversation(): void
    {
        $user = $this->createAuthenticatedUser();

        $clientA = $this->makeClient(['name' => 'Client A']);
        $clientB = $this->makeClient(['name' => 'Client B']);

        // User only has access to Client A
        $user->clients()->attach($clientA->id, ['role' => 'admin']);

        // Create conversation in Client B
        [$conversationB, $tokenB] = $this->makeConversation($clientB);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/conversations/' . $conversationB->id . '/events');

        $response->assertForbidden()
            ->assertJson(['error' => 'Access denied to this conversation']);
    }

    public function test_events_endpoint_supports_after_id_filter(): void
    {
        $user = $this->createAuthenticatedUser();

        $client = $this->makeClient();
        $user->clients()->attach($client->id, ['role' => 'admin']);

        [$conversation, $token] = $this->makeConversation($client);

        // Add events
        $result1 = $this->recordUserMessage($conversation, 'First');
        $result2 = $this->recordAssistantMessage($conversation, 'Second');
        $result3 = $this->recordUserMessage($conversation, 'Third');

        // Get events after the first one
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/conversations/' . $conversation->id . '/events?after_id=' . $result1['event']->id);

        $response->assertOk();

        $events = $response->json('events');
        $this->assertCount(2, $events);

        // Should only have events 2 and 3
        $this->assertSame($result2['event']->id, $events[0]['id']);
        $this->assertSame($result3['event']->id, $events[1]['id']);
    }
}
