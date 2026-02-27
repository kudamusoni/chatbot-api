<?php

namespace Tests\Feature\Http\Widget;

use App\Enums\WidgetDenyReason;
use App\Models\ClientSetting;
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
                'widget_security_version',
                'widget' => [
                    'client_name',
                    'bot_name',
                    'brand_color',
                    'accent_color',
                    'logo_url',
                    'prompt_settings',
                    'preset_questions',
                ],
            ]);

        // Token should be 64 characters
        $this->assertSame(64, strlen($response->json('session_token')));

        // Conversation should exist in database
        $this->assertDatabaseHas('conversations', [
            'id' => $response->json('conversation_id'),
            'client_id' => $client->id,
        ]);

        $this->assertDatabaseCount('conversation_messages', 0);
        $response->assertJsonPath('widget.preset_questions', []);
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

    public function test_bootstrap_requires_origin_when_origin_checks_enabled(): void
    {
        config()->set('widget.security.bypass_local_origin_checks', false);
        $client = $this->makeClient([
            'settings' => [
                'allowed_origins' => ['https://example.com'],
            ],
        ]);

        $response = $this->postJson('/api/widget/bootstrap', [
            'client_id' => $client->id,
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'reason_code' => WidgetDenyReason::ORIGIN_MISSING_BOOTSTRAP->value,
            ]);
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

    public function test_bootstrap_returns_default_widget_settings_when_missing(): void
    {
        $client = $this->makeClient(['name' => 'Acme Auctions']);

        $response = $this->postJson('/api/widget/bootstrap', [
            'client_id' => $client->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('widget.client_name', 'Acme Auctions')
            ->assertJsonPath('widget.bot_name', null)
            ->assertJsonPath('widget.brand_color', null)
            ->assertJsonPath('widget.accent_color', null)
            ->assertJsonPath('widget.logo_url', null)
            ->assertJsonPath('widget.prompt_settings', [])
            ->assertJsonPath('widget.preset_questions', []);
    }

    public function test_bootstrap_returns_custom_widget_settings(): void
    {
        $client = $this->makeClient(['name' => 'Acme Auctions']);
        ClientSetting::forClientOrCreate((string) $client->id)->update([
            'bot_name' => 'Acme Assistant',
            'brand_color' => '#0EA5E9',
            'accent_color' => '#22C55E',
            'logo_url' => 'https://cdn.example.com/logo.png',
            'prompt_settings' => [
                'preset_questions' => [
                    'How much is this worth?',
                    'Can I request a manual review?',
                ],
            ],
            'widget_security_version' => 7,
        ]);

        $response = $this->postJson('/api/widget/bootstrap', [
            'client_id' => $client->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('widget.client_name', 'Acme Auctions')
            ->assertJsonPath('widget.bot_name', 'Acme Assistant')
            ->assertJsonPath('widget.brand_color', '#0EA5E9')
            ->assertJsonPath('widget.accent_color', '#22C55E')
            ->assertJsonPath('widget.logo_url', 'https://cdn.example.com/logo.png')
            ->assertJsonPath('widget_security_version', 7)
            ->assertJsonPath('widget.preset_questions.0', 'How much is this worth?')
            ->assertJsonPath('widget.preset_questions.1', 'Can I request a manual review?');
    }
}
