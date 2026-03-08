<?php

namespace Tests\Feature\Http\App;

use App\Models\Client;
use App\Models\ClientSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsAndEmbedEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_can_get_settings_and_embed_code(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $viewer = User::factory()->create();
        $viewer->clients()->attach($client->id, ['role' => 'viewer']);

        $this->actingAs($viewer, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->getJson('/app/settings')
            ->assertOk()
            ->assertJsonStructure([
                'client' => ['id', 'name'],
                'settings' => [
                    'bot_name',
                    'brand_color',
                    'accent_color',
                    'logo_url',
                    'prompt_settings',
                    'allowed_origins',
                    'widget_security_version',
                ],
            ])
            ->assertJsonMissingPath('permissions');

        $this->actingAs($viewer, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->getJson('/app/embed-code')
            ->assertOk()
            ->assertJsonStructure([
                'script_url',
                'params' => ['client_id', 'widget_security_version'],
                'widget_security_version',
                'snippet',
            ]);
    }

    public function test_viewer_cannot_put_settings(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $viewer = User::factory()->create();
        $viewer->clients()->attach($client->id, ['role' => 'viewer']);

        $this->actingAs($viewer, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->putJson('/app/settings', [
                'client_name' => 'Blocked',
                'bot_name' => 'Nope',
            ])
            ->assertStatus(403)
            ->assertJson([
                'error' => 'FORBIDDEN',
                'reason_code' => 'INSUFFICIENT_ROLE',
            ]);
    }

    public function test_admin_can_put_settings_and_allowed_origins_are_persisted_and_version_bumped_when_changed(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $admin = User::factory()->create();
        $admin->clients()->attach($client->id, ['role' => 'admin']);

        ClientSetting::create([
            'client_id' => $client->id,
            'bot_name' => 'Old Bot',
            'brand_color' => '#111111',
            'accent_color' => '#222222',
            'logo_url' => 'https://old.example/logo.png',
            'prompt_settings' => ['tone' => 'formal', 'old' => true],
            'allowed_origins' => ['https://example.com'],
            'widget_security_version' => 7,
        ]);

        $response = $this->actingAs($admin, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->putJson('/app/settings', [
                'client_name' => 'Acme Auctions',
                'bot_name' => 'Acme Assistant',
                'brand_color' => '#0EA5E9',
                'accent_color' => '#22C55E',
                'logo_url' => 'https://cdn.example.com/logo.png',
                'prompt_settings' => ['tone' => 'concise'],
                'allowed_origins' => [
                    'https://Sub.Example.com:443/',
                    'https://sub.example.com',
                ],
                'widget_security_version' => 999,
                'unknown_setting' => 'ignore-me',
            ])
            ->assertOk();

        $response->assertJsonPath('client.name', 'Acme Auctions');
        $response->assertJsonPath('settings.bot_name', 'Acme Assistant');
        $response->assertJsonPath('settings.prompt_settings', ['tone' => 'concise']);
        $response->assertJsonPath('settings.allowed_origins', ['https://sub.example.com']);
        $response->assertJsonPath('settings.widget_security_version', 8);

        $client->refresh();
        $this->assertSame('Acme Auctions', $client->name);

        $settings = ClientSetting::where('client_id', $client->id)->firstOrFail();
        $this->assertSame('Acme Assistant', $settings->bot_name);
        $this->assertSame('#0EA5E9', $settings->brand_color);
        $this->assertSame('#22C55E', $settings->accent_color);
        $this->assertSame('https://cdn.example.com/logo.png', $settings->logo_url);
        $this->assertSame(['tone' => 'concise'], $settings->prompt_settings);
        $this->assertSame(['https://sub.example.com'], $settings->allowed_origins);
        $this->assertSame(8, (int) $settings->widget_security_version);
    }

    public function test_get_settings_auto_creates_default_settings_row(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $viewer = User::factory()->create();
        $viewer->clients()->attach($client->id, ['role' => 'viewer']);

        $this->assertDatabaseMissing('client_settings', ['client_id' => $client->id]);

        $this->actingAs($viewer, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->getJson('/app/settings')
            ->assertOk()
            ->assertJsonPath('settings.prompt_settings', [])
            ->assertJsonPath('settings.allowed_origins', [])
            ->assertJsonPath('settings.widget_security_version', 1);

        $this->assertDatabaseHas('client_settings', ['client_id' => $client->id]);
    }

    public function test_admin_can_put_settings_using_flat_fallback_message_field(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $admin = User::factory()->create();
        $admin->clients()->attach($client->id, ['role' => 'admin']);

        ClientSetting::create([
            'client_id' => $client->id,
            'bot_name' => 'Old Bot',
            'prompt_settings' => ['tone' => 'formal'],
            'widget_security_version' => 3,
        ]);

        $this->actingAs($admin, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->putJson('/app/settings', [
                'bot_name' => 'Kuda Test',
                'fallback_message' => null,
            ])
            ->assertOk()
            ->assertJsonPath('settings.bot_name', 'Kuda Test')
            ->assertJsonPath('settings.prompt_settings.fallback_message', null);

        $settings = ClientSetting::query()->where('client_id', $client->id)->firstOrFail();
        $this->assertSame('Kuda Test', $settings->bot_name);
        $this->assertSame(['tone' => 'formal', 'fallback_message' => null], $settings->prompt_settings);
    }

    public function test_admin_can_put_settings_using_flat_canonical_payload_shape(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $admin = User::factory()->create();
        $admin->clients()->attach($client->id, ['role' => 'admin']);

        $this->actingAs($admin, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->putJson('/app/settings', [
                'bot_name' => 'Kuda Test',
                'colors' => [],
                'prompt_settings' => [],
                'business_details' => [],
                'urls' => [],
                'widget_enabled' => false,
                'allowed_origins' => [],
                'widget_security_version' => 1,
                'brand_color' => null,
                'accent_color' => null,
                'logo_url' => null,
                'fallback_message' => null,
            ])
            ->assertOk()
            ->assertJsonPath('settings.bot_name', 'Kuda Test')
            ->assertJsonPath('settings.prompt_settings.fallback_message', null)
            ->assertJsonPath('settings.allowed_origins', [])
            ->assertJsonPath('settings.widget_security_version', 1);

        $settings = ClientSetting::query()->where('client_id', $client->id)->firstOrFail();
        $this->assertSame('Kuda Test', $settings->bot_name);
        $this->assertSame(['fallback_message' => null], $settings->prompt_settings);
        $this->assertNull($settings->brand_color);
        $this->assertNull($settings->accent_color);
        $this->assertNull($settings->logo_url);
    }

    public function test_admin_can_put_settings_with_flat_preset_questions(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $admin = User::factory()->create();
        $admin->clients()->attach($client->id, ['role' => 'admin']);

        $this->actingAs($admin, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->putJson('/app/settings', [
                'preset_questions' => [
                    'How much is this worth?',
                    'Can I request a manual review?',
                    'How much is this worth?',
                    '',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('settings.prompt_settings.preset_questions.0', 'How much is this worth?')
            ->assertJsonPath('settings.prompt_settings.preset_questions.1', 'Can I request a manual review?');

        $settings = ClientSetting::query()->where('client_id', $client->id)->firstOrFail();
        $this->assertSame([
            'preset_questions' => [
                'How much is this worth?',
                'Can I request a manual review?',
            ],
        ], $settings->prompt_settings);
    }

    public function test_intro_message_in_prompt_settings_is_ignored_on_save(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $admin = User::factory()->create();
        $admin->clients()->attach($client->id, ['role' => 'admin']);

        $this->actingAs($admin, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->putJson('/app/settings', [
                'prompt_settings' => [
                    'intro_message' => 'Yoo. Cuzzy',
                    'fallback_message' => 'Thank you for the message. Do you have any more questions?',
                    'preset_questions' => [
                        'I want to sell an item',
                        'Where are you located?',
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonMissingPath('settings.prompt_settings.intro_message')
            ->assertJsonPath('settings.prompt_settings.fallback_message', 'Thank you for the message. Do you have any more questions?')
            ->assertJsonPath('settings.prompt_settings.preset_questions.0', 'I want to sell an item')
            ->assertJsonPath('settings.prompt_settings.preset_questions.1', 'Where are you located?');

        $settings = ClientSetting::query()->where('client_id', $client->id)->firstOrFail();
        $this->assertSame([
            'fallback_message' => 'Thank you for the message. Do you have any more questions?',
            'preset_questions' => [
                'I want to sell an item',
                'Where are you located?',
            ],
        ], $settings->prompt_settings);
    }

    public function test_admin_put_settings_creates_client_settings_row_when_missing(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $admin = User::factory()->create();
        $admin->clients()->attach($client->id, ['role' => 'admin']);

        $this->assertDatabaseMissing('client_settings', ['client_id' => $client->id]);

        $this->actingAs($admin, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->putJson('/app/settings', [
                'bot_name' => 'Created On Save',
            ])
            ->assertOk()
            ->assertJsonPath('settings.bot_name', 'Created On Save')
            ->assertJsonPath('settings.widget_security_version', 1);

        $this->assertDatabaseHas('client_settings', [
            'client_id' => $client->id,
            'bot_name' => 'Created On Save',
            'widget_security_version' => 1,
        ]);
    }

    public function test_embed_code_snippet_contract_is_stable(): void
    {
        config()->set('widget.script_url', 'https://api.example.com/widget.js');
        config()->set('widget.api_url', 'https://widget.example.com');

        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $viewer = User::factory()->create();
        $viewer->clients()->attach($client->id, ['role' => 'viewer']);

        ClientSetting::create([
            'client_id' => $client->id,
            'widget_security_version' => 5,
        ]);

        $response = $this->actingAs($viewer, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->getJson('/app/embed-code')
            ->assertOk();

        $response->assertJson([
            'script_url' => 'https://api.example.com/widget.js',
            'api_url' => 'https://widget.example.com',
            'params' => [
                'client_id' => $client->id,
                'api_url' => 'https://widget.example.com',
                'widget_security_version' => 5,
            ],
            'widget_security_version' => 5,
        ]);

        $snippet = (string) $response->json('snippet');
        $this->assertStringContainsString('<script src="https://api.example.com/widget.js" defer', $snippet);
        $this->assertStringContainsString('data-auto-init', $snippet);
        $this->assertStringContainsString('data-client-id="' . $client->id . '"', $snippet);
        $this->assertStringContainsString('data-api-url="https://widget.example.com"', $snippet);
        $this->assertStringContainsString('data-widget-security-version="5"', $snippet);
    }
}
