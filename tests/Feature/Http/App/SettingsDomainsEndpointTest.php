<?php

namespace Tests\Feature\Http\App;

use App\Models\Client;
use App\Models\ClientSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsDomainsEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_cannot_update_domains(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $user = User::factory()->create();
        $user->clients()->attach($client->id, ['role' => 'viewer']);

        $this->actingAs($user, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->putJson('/app/settings/domains', [
                'allowed_origins' => ['https://example.com'],
            ])
            ->assertStatus(403)
            ->assertJson([
                'error' => 'FORBIDDEN',
                'reason_code' => 'INSUFFICIENT_ROLE',
            ]);
    }

    public function test_wildcard_origins_are_rejected_with_422(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $user = User::factory()->create();
        $user->clients()->attach($client->id, ['role' => 'admin']);

        $this->actingAs($user, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->putJson('/app/settings/domains', [
                'allowed_origins' => ['*', 'https://*.example.com'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['allowed_origins.0', 'allowed_origins.1']);
    }

    public function test_max_origins_limit_of_50_is_enforced(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $user = User::factory()->create();
        $user->clients()->attach($client->id, ['role' => 'admin']);

        $origins = [];
        for ($i = 0; $i < 51; $i++) {
            $origins[] = "https://example{$i}.com";
        }

        $this->actingAs($user, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->putJson('/app/settings/domains', [
                'allowed_origins' => $origins,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['allowed_origins']);
    }

    public function test_domains_are_normalized_and_response_echoes_canonical_list_and_incremented_version(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $user = User::factory()->create();
        $user->clients()->attach($client->id, ['role' => 'admin']);

        ClientSetting::create([
            'client_id' => $client->id,
            'allowed_origins' => ['https://old.example.com'],
            'widget_security_version' => 4,
        ]);

        $response = $this->actingAs($user, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->putJson('/app/settings/domains', [
                'allowed_origins' => [
                    '  HTTPS://Example.com/  ',
                    'https://example.com:443',
                    'https://example.com:8443',
                    'http://localhost:80',
                    'http://localhost:8080',
                ],
            ])
            ->assertOk();

        $response->assertJson([
            'ok' => true,
            'widget_security_version' => 5,
            'allowed_origins' => [
                'https://example.com',
                'https://example.com:8443',
                'http://localhost',
                'http://localhost:8080',
            ],
        ]);

        $this->assertDatabaseHas('client_settings', [
            'client_id' => $client->id,
            'widget_security_version' => 5,
        ]);
    }
}

