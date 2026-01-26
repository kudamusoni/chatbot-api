<?php

namespace Tests\Feature\Http\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_sanctum_token(): void
    {
        $user = User::create([
            'name' => 'Test Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/admin/login', [
            'email' => 'admin@example.com',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'token',
                'user' => ['id', 'name', 'email'],
            ]);

        // Token should be a non-empty string
        $this->assertNotEmpty($response->json('token'));
        $this->assertSame('admin@example.com', $response->json('user.email'));
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        $user = User::create([
            'name' => 'Test Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/admin/login', [
            'email' => 'admin@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertUnauthorized()
            ->assertJson(['error' => 'Invalid credentials']);
    }

    public function test_login_fails_with_non_existent_user(): void
    {
        $response = $this->postJson('/api/admin/login', [
            'email' => 'nonexistent@example.com',
            'password' => 'password123',
        ]);

        $response->assertUnauthorized()
            ->assertJson(['error' => 'Invalid credentials']);
    }

    public function test_login_validates_required_fields(): void
    {
        $response = $this->postJson('/api/admin/login', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_login_validates_email_format(): void
    {
        $response = $this->postJson('/api/admin/login', [
            'email' => 'not-an-email',
            'password' => 'password123',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }
}
