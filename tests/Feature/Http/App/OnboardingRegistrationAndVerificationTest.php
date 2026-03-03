<?php

namespace Tests\Feature\Http\App;

use App\Mail\VerifyEmailMail;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class OnboardingRegistrationAndVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_user_client_membership_and_returns_unverified_boot_payload(): void
    {
        Mail::fake();

        $response = $this->postJson('/app/onboarding/register', [
            'name' => 'Kuda',
            'email' => 'Kuda@Email.com',
            'password' => 'password123',
            'company_name' => 'Acme Auctions',
        ])->assertCreated();

        $user = User::query()->where('email', 'kuda@email.com')->first();
        $this->assertNotNull($user);

        $client = Client::query()->where('name', 'Acme Auctions')->first();
        $this->assertNotNull($client);

        $this->assertDatabaseHas('client_user', [
            'client_id' => $client->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $response->assertJsonPath('requires_email_verification', true);
        $response->assertJsonPath('user.verified', false);
        $this->assertSame([], $response->json('permissions'));

        Mail::assertQueued(VerifyEmailMail::class);

        $this->assertDatabaseHas('app_logs', ['action' => 'user.registered']);
        $this->assertDatabaseHas('app_logs', ['action' => 'client.created']);
        $this->assertDatabaseHas('app_logs', ['action' => 'client.member.added']);
    }

    public function test_register_duplicate_email_returns_email_taken(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/app/onboarding/register', [
            'name' => 'Kuda',
            'email' => 'taken@example.com',
            'password' => 'password123',
            'company_name' => 'Acme Auctions',
        ])->assertStatus(409)
            ->assertJson([
                'error' => 'CONFLICT',
                'reason_code' => 'EMAIL_TAKEN',
            ]);
    }

    public function test_verify_email_marks_user_verified_and_is_idempotent(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'verify@example.com',
        ]);

        $url = URL::temporarySignedRoute(
            'app.onboarding.verify-email',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $this->getJson($url)->assertOk()->assertJson(['ok' => true]);
        $user->refresh();
        $this->assertNotNull($user->email_verified_at);

        $this->getJson($url)->assertOk()->assertJson(['ok' => true]);
    }

    public function test_verify_email_invalid_or_expired_link_returns_reason_codes(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'verify2@example.com',
        ]);

        $invalidUrl = URL::temporarySignedRoute(
            'app.onboarding.verify-email',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        ) . 'x';

        $this->getJson($invalidUrl)->assertStatus(409)
            ->assertJsonPath('reason_code', 'VERIFY_LINK_INVALID');

        $expiredUrl = URL::temporarySignedRoute(
            'app.onboarding.verify-email',
            now()->subMinute(),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $this->getJson($expiredUrl)->assertStatus(409)
            ->assertJsonPath('reason_code', 'VERIFY_LINK_EXPIRED');
    }

    public function test_resend_verification_queues_mail_and_verified_is_idempotent(): void
    {
        Mail::fake();

        $user = User::factory()->unverified()->create();
        $this->actingAs($user, 'web')
            ->postJson('/app/onboarding/resend-verification')
            ->assertOk()
            ->assertJson(['ok' => true]);

        Mail::assertQueued(VerifyEmailMail::class);

        $verified = User::factory()->create();
        $this->actingAs($verified, 'web')
            ->postJson('/app/onboarding/resend-verification')
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_resend_verification_has_cooldown_and_returns_retry_after_seconds(): void
    {
        Config::set('auth.verification.resend_cooldown_seconds', 60);
        Mail::fake();

        $user = User::factory()->unverified()->create();

        $this->actingAs($user, 'web')
            ->postJson('/app/onboarding/resend-verification')
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->actingAs($user, 'web')
            ->postJson('/app/onboarding/resend-verification')
            ->assertStatus(429)
            ->assertJsonPath('error', 'TOO_MANY_REQUESTS')
            ->assertJsonPath('reason_code', 'RATE_LIMITED')
            ->assertJsonStructure(['retry_after_seconds']);
    }

    public function test_auth_me_returns_verify_pending_payload_for_unverified_user(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $user = User::factory()->unverified()->create();
        $user->clients()->attach($client->id, ['role' => 'owner']);

        $this->actingAs($user, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->getJson('/app/auth/me')
            ->assertOk()
            ->assertJsonPath('requires_email_verification', true)
            ->assertJsonPath('user.verified', false)
            ->assertJsonPath('active_client.id', $client->id)
            ->assertJsonPath('accessible_clients_count', 1)
            ->assertJsonPath('tenant_role', 'owner')
            ->assertJsonPath('permissions', []);
    }
}
