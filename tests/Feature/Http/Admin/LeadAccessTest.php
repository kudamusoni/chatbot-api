<?php

namespace Tests\Feature\Http\Admin;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\InteractsWithConversations;
use Tests\TestCase;

class LeadAccessTest extends TestCase
{
    use RefreshDatabase, InteractsWithConversations;

    private function createAuthenticatedUser(array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Test Admin',
            'email' => 'manual-review-admin@example.com',
            'password' => Hash::make('password123'),
        ], $attributes));
    }

    public function test_list_requires_authentication(): void
    {
        $client = $this->makeClient();

        $this->getJson('/api/admin/leads?client_id=' . $client->id)
            ->assertUnauthorized();
    }

    public function test_list_enforces_client_access(): void
    {
        $user = $this->createAuthenticatedUser();
        $clientA = $this->makeClient(['name' => 'Client A']);
        $clientB = $this->makeClient(['name' => 'Client B']);
        [$conversationB, ] = $this->makeConversation($clientB);

        $user->clients()->attach($clientA->id, ['role' => 'admin']);

        Lead::create([
            'conversation_id' => $conversationB->id,
            'client_id' => $clientB->id,
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'phone_raw' => '(202) 555-0110',
            'phone_normalized' => '2025550110',
            'status' => 'REQUESTED',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/leads?client_id=' . $clientB->id)
            ->assertForbidden()
            ->assertJson(['error' => 'Access denied to this client']);
    }

    public function test_list_returns_masked_contact_fields(): void
    {
        $user = $this->createAuthenticatedUser();
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client);
        $user->clients()->attach($client->id, ['role' => 'admin']);

        $request = Lead::create([
            'conversation_id' => $conversation->id,
            'client_id' => $client->id,
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'phone_raw' => '+1 (202) 555-0110',
            'phone_normalized' => '+12025550110',
            'status' => 'REQUESTED',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/leads?client_id=' . $client->id);

        $response->assertOk()
            ->assertJsonStructure([
                'leads' => [
                    '*' => ['id', 'conversation_id', 'status', 'email_masked', 'phone_masked', 'created_at'],
                ],
                'pagination',
            ]);

        $item = collect($response->json('leads'))->firstWhere('id', $request->id);
        $this->assertNotNull($item);
        $this->assertSame('j***@example.com', $item['email_masked']);
        $this->assertSame('***-***-0110', $item['phone_masked']);
    }

    public function test_show_returns_full_details_for_authorized_user(): void
    {
        $user = $this->createAuthenticatedUser();
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client);
        $user->clients()->attach($client->id, ['role' => 'admin']);

        $request = Lead::create([
            'conversation_id' => $conversation->id,
            'client_id' => $client->id,
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'phone_raw' => '202-555-0110',
            'phone_normalized' => '2025550110',
            'status' => 'REQUESTED',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/leads/' . $request->id);

        $response->assertOk()
            ->assertJson([
                'lead' => [
                    'id' => $request->id,
                    'name' => 'Jane Doe',
                    'email' => 'jane@example.com',
                    'phone_raw' => '202-555-0110',
                    'phone_normalized' => '2025550110',
                    'status' => 'REQUESTED',
                ],
            ]);
    }

    public function test_show_denies_unauthorized_user(): void
    {
        $user = $this->createAuthenticatedUser();
        $clientA = $this->makeClient(['name' => 'Client A']);
        $clientB = $this->makeClient(['name' => 'Client B']);
        [$conversationB, ] = $this->makeConversation($clientB);
        $user->clients()->attach($clientA->id, ['role' => 'admin']);

        $request = Lead::create([
            'conversation_id' => $conversationB->id,
            'client_id' => $clientB->id,
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'phone_raw' => '202-555-0110',
            'phone_normalized' => '2025550110',
            'status' => 'REQUESTED',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/leads/' . $request->id)
            ->assertForbidden()
            ->assertJson(['error' => 'Access denied to this request']);
    }
}
