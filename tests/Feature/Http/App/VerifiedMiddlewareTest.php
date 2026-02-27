<?php

namespace Tests\Feature\Http\App;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VerifiedMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_unverified_user_is_blocked_from_protected_app_routes(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $user = User::factory()->unverified()->create();
        $user->clients()->attach($client->id, ['role' => 'viewer']);

        $this->actingAs($user, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->getJson('/app/leads')
            ->assertStatus(403)
            ->assertJson([
                'error' => 'FORBIDDEN',
                'reason_code' => 'EMAIL_NOT_VERIFIED',
            ]);
    }
}

