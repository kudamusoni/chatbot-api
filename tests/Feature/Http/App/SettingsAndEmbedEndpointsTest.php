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
                'client' => ['name' => 'Blocked'],
                'settings' => ['bot_name' => 'Nope'],
            ])
            ->assertStatus(403)
            ->assertJson([
                'error' => 'FORBIDDEN',
                'reason_code' => 'INSUFFICIENT_ROLE',
            ]);
    }

    public function test_admin_can_put_settings_and_unknown_keys_are_ignored_and_domains_version_unchanged(): void
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
                'client' => ['name' => 'Acme Auctions'],
                'settings' => [
                    'bot_name' => 'Acme Assistant',
                    'brand_color' => '#0EA5E9',
                    'accent_color' => '#22C55E',
                    'logo_url' => 'https://cdn.example.com/logo.png',
                    'prompt_settings' => ['tone' => 'concise'],
                    'allowed_origins' => ['https://malicious-write-should-ignore.example'],
                    'widget_security_version' => 999,
                    'unknown_setting' => 'ignore-me',
                ],
            ])
            ->assertOk();

        $response->assertJsonPath('client.name', 'Acme Auctions');
        $response->assertJsonPath('settings.bot_name', 'Acme Assistant');
        $response->assertJsonPath('settings.prompt_settings', ['tone' => 'concise']);
        $response->assertJsonPath('settings.allowed_origins', ['https://example.com']);
        $response->assertJsonPath('settings.widget_security_version', 7);

        $client->refresh();
        $this->assertSame('Acme Auctions', $client->name);

        $settings = ClientSetting::where('client_id', $client->id)->firstOrFail();
        $this->assertSame('Acme Assistant', $settings->bot_name);
        $this->assertSame('#0EA5E9', $settings->brand_color);
        $this->assertSame('#22C55E', $settings->accent_color);
        $this->assertSame('https://cdn.example.com/logo.png', $settings->logo_url);
        $this->assertSame(['tone' => 'concise'], $settings->prompt_settings);
        $this->assertSame(['https://example.com'], $settings->allowed_origins);
        $this->assertSame(7, (int) $settings->widget_security_version);
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

    public function test_embed_code_snippet_contract_is_stable(): void
    {
        config()->set('widget.script_url', 'https://api.example.com/widget.js');

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
            'params' => [
                'client_id' => $client->id,
                'widget_security_version' => 5,
            ],
            'widget_security_version' => 5,
        ]);

        $snippet = (string) $response->json('snippet');
        $this->assertStringContainsString('<script src="https://api.example.com/widget.js" defer', $snippet);
        $this->assertStringContainsString('data-client-id="' . $client->id . '"', $snippet);
        $this->assertStringContainsString('data-widget-security-version="5"', $snippet);
    }
}

