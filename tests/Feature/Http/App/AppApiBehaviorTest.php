<?php

namespace Tests\Feature\Http\App;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppApiBehaviorTest extends TestCase
{
    use RefreshDatabase;

    public function test_app_routes_return_json_401_when_unauthenticated(): void
    {
        $response = $this->getJson('/app/leads');

        $response->assertStatus(401)
            ->assertJson([
                'error' => 'UNAUTHENTICATED',
                'reason_code' => 'UNAUTHENTICATED',
            ]);
        $this->assertTrue(str_contains((string) $response->headers->get('content-type'), 'application/json'));
    }

    public function test_app_login_validation_is_json_422(): void
    {
        $response = $this->postJson('/app/auth/login', []);

        $response->assertStatus(422);
        $this->assertTrue(str_contains((string) $response->headers->get('content-type'), 'application/json'));
    }

    public function test_returns_no_active_client_when_missing_client_context(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->getJson('/app/leads')
            ->assertStatus(409)
            ->assertJson([
                'error' => 'CONFLICT',
                'reason_code' => 'NO_ACTIVE_CLIENT',
            ]);
    }

    public function test_forbidden_switch_is_json_403(): void
    {
        $user = User::factory()->create();
        $clientA = Client::create(['name' => 'A', 'slug' => 'a', 'settings' => []]);
        $clientB = Client::create(['name' => 'B', 'slug' => 'b', 'settings' => []]);

        $user->clients()->attach($clientA->id, ['role' => 'viewer']);

        $this->actingAs($user, 'web')
            ->postJson("/app/clients/{$clientB->id}/switch")
            ->assertStatus(403)
            ->assertJson([
                'error' => 'FORBIDDEN',
                'reason_code' => 'NOT_A_CLIENT_MEMBER',
            ]);
    }
}
