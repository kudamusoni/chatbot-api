<?php

namespace App\Http\Controllers\App;

use App\Enums\AppDenyReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\AcceptInviteRequest;
use App\Http\Requests\App\RegisterOwnerRequest;
use App\Models\ClientInvitation;
use App\Services\AppBootPayloadService;
use App\Services\AuditLogger;
use App\Services\EmailVerificationService;
use App\Services\InviteService;
use App\Services\OnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class OnboardingController extends Controller
{
    public function __construct(
        private readonly OnboardingService $onboardingService,
        private readonly EmailVerificationService $emailVerificationService,
        private readonly InviteService $inviteService,
        private readonly AppBootPayloadService $bootPayloadService,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function register(RegisterOwnerRequest $request): JsonResponse
    {
        try {
            $result = $this->onboardingService->registerOwnerAndClient($request->validated());
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === AppDenyReason::EMAIL_TAKEN->value) {
                return response()->json([
                    'error' => 'CONFLICT',
                    'reason_code' => AppDenyReason::EMAIL_TAKEN->value,
                ], 409);
            }

            throw $e;
        } catch (QueryException $e) {
            if (str_contains(mb_strtolower($e->getMessage()), 'users_email_unique')) {
                return response()->json([
                    'error' => 'CONFLICT',
                    'reason_code' => AppDenyReason::EMAIL_TAKEN->value,
                ], 409);
            }

            throw $e;
        }

        $user = $result['user'];
        $client = $result['client'];

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('active_client_id', $client->id);

        $this->emailVerificationService->sendVerification($user);

        $this->auditLogger->log($user, 'user.registered', null, [
            'user_id' => $user->id,
            'email' => $user->email,
            'ip' => $request->ip(),
            'ua' => (string) $request->userAgent(),
        ]);

        $this->auditLogger->log($user, 'client.created', $client->id, [
            'client_id' => $client->id,
            'name' => $client->name,
            'ip' => $request->ip(),
            'ua' => (string) $request->userAgent(),
        ]);

        $this->auditLogger->log($user, 'client.member.added', $client->id, [
            'client_id' => $client->id,
            'target_user_id' => $user->id,
            'role' => 'owner',
            'ip' => $request->ip(),
            'ua' => (string) $request->userAgent(),
        ]);

        return response()->json($this->bootPayloadService->forUser($request, $user), 201);
    }

    public function verifyEmail(Request $request): JsonResponse
    {
        $result = $this->emailVerificationService->verifySignedRequest($request);

        if (($result['ok'] ?? false) !== true) {
            $reason = (string) ($result['reason'] ?? AppDenyReason::VERIFY_LINK_INVALID->value);
            $status = $reason === AppDenyReason::VERIFY_LINK_EXPIRED->value ? 409 : 409;

            return response()->json([
                'error' => 'CONFLICT',
                'reason_code' => $reason,
            ], $status);
        }

        /** @var \App\Models\User $user */
        $user = $result['user'];
        if (($result['already_verified'] ?? false) === false) {
            $this->auditLogger->log($user, 'user.email.verified', null, [
                'user_id' => $user->id,
                'ip' => $request->ip(),
                'ua' => (string) $request->userAgent(),
            ]);
        }

        return response()->json(['ok' => true]);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        if ($user->email_verified_at !== null) {
            return response()->json(['ok' => true]);
        }

        $cooldownSeconds = max(1, (int) config('auth.verification.resend_cooldown_seconds', 60));
        $cacheKey = sprintf('email-verification:resend-cooldown:%d', $user->id);
        $nextAllowedAt = (int) Cache::get($cacheKey, 0);
        $retryAfter = max(0, $nextAllowedAt - now()->timestamp);

        if ($retryAfter > 0) {
            return response()->json([
                'error' => 'TOO_MANY_REQUESTS',
                'reason_code' => AppDenyReason::RATE_LIMITED->value,
                'retry_after_seconds' => $retryAfter,
            ], 429);
        }

        $this->emailVerificationService->sendVerification($user);
        Cache::put($cacheKey, now()->timestamp + $cooldownSeconds, $cooldownSeconds);
        $this->auditLogger->log($user, 'user.email.verification_resent', null, [
            'user_id' => $user->id,
            'ip' => $request->ip(),
            'ua' => (string) $request->userAgent(),
        ]);

        return response()->json(['ok' => true]);
    }

    public function previewInvitation(Request $request): JsonResponse
    {
        $rawToken = (string) $request->query('token', '');
        if ($rawToken === '') {
            return response()->json([
                'error' => 'NOT_FOUND',
                'reason_code' => AppDenyReason::INVITE_INVALID->value,
            ], 404);
        }

        $tokenHash = hash('sha256', $rawToken);
        /** @var ClientInvitation|null $invite */
        $invite = ClientInvitation::query()
            ->with('client')
            ->where('token_hash', $tokenHash)
            ->first();

        if (!$invite) {
            return response()->json([
                'error' => 'NOT_FOUND',
                'reason_code' => AppDenyReason::INVITE_INVALID->value,
            ], 404);
        }

        if ($invite->accepted_at !== null) {
            return response()->json([
                'error' => 'CONFLICT',
                'reason_code' => AppDenyReason::INVITE_ACCEPTED->value,
            ], 409);
        }

        if ($invite->revoked_at !== null) {
            return response()->json([
                'error' => 'GONE',
                'reason_code' => AppDenyReason::INVITE_REVOKED->value,
            ], 410);
        }

        if ($invite->expires_at?->isPast()) {
            return response()->json([
                'error' => 'GONE',
                'reason_code' => AppDenyReason::INVITE_EXPIRED->value,
            ], 410);
        }

        return response()->json([
            'client_name' => (string) ($invite->client?->name ?? ''),
            'role' => $invite->role,
            'expires_at' => $invite->expires_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
        ]);
    }

    public function acceptInvitation(AcceptInviteRequest $request): JsonResponse
    {
        $validated = $request->validated();
        try {
            $result = $this->inviteService->acceptInvite(
                (string) $validated['token'],
                $validated['name'] ?? null,
                $validated['password'] ?? null
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => [
                    'name' => ['The name field is required for new invited users.'],
                    'password' => ['The password field is required for new invited users.'],
                ],
            ], 422);
        }

        if (($result['ok'] ?? false) !== true) {
            $reason = (string) ($result['reason'] ?? AppDenyReason::INVITE_INVALID->value);
            $status = (int) ($result['status'] ?? 404);
            $error = $status === 404 ? 'NOT_FOUND' : ($status === 410 ? 'GONE' : 'CONFLICT');

            return response()->json([
                'error' => $error,
                'reason_code' => $reason,
            ], $status);
        }

        /** @var \App\Models\User $user */
        $user = $result['user'];
        /** @var ClientInvitation $invite */
        $invite = $result['invite'];

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('active_client_id', $invite->client_id);

        if ($user->email_verified_at === null) {
            $this->emailVerificationService->sendVerification($user);
        }

        $this->auditLogger->log($user, 'invite.accepted', $invite->client_id, [
            'invitation_id' => $invite->id,
            'role' => $invite->role,
            'ip' => $request->ip(),
            'ua' => (string) $request->userAgent(),
        ]);

        if (($result['membership_created'] ?? false) === true) {
            $this->auditLogger->log($user, 'client.member.added', $invite->client_id, [
                'client_id' => $invite->client_id,
                'target_user_id' => $user->id,
                'role' => $invite->role,
                'source' => 'invite.accept',
                'ip' => $request->ip(),
                'ua' => (string) $request->userAgent(),
            ]);
        }

        return response()->json($this->bootPayloadService->forUser($request, $user));
    }
}
