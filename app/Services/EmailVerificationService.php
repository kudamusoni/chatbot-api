<?php

namespace App\Services;

use App\Mail\VerifyEmailMail;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

class EmailVerificationService
{
    public function sendVerification(User $user): void
    {
        $expiresAt = CarbonImmutable::now('UTC')->addMinutes((int) config('auth.verification.expire', 60));
        $backendUrl = URL::temporarySignedRoute(
            'app.onboarding.verify-email',
            $expiresAt,
            [
                'id' => $user->id,
                'hash' => sha1((string) $user->email),
            ],
            absolute: false
        );

        $query = parse_url($backendUrl, PHP_URL_QUERY) ?: '';
        $frontendUrl = rtrim((string) config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173')), '/')
            . '/verify-email'
            . ($query !== '' ? "?{$query}" : '');

        Mail::to($user->email)->queue(new VerifyEmailMail(
            (string) $user->name,
            $frontendUrl
        ));
    }

    /**
     * @return array{ok:bool,reason?:string,user?:User,already_verified?:bool}
     */
    public function verifySignedRequest(Request $request): array
    {
        $id = $request->query('id');
        $hash = (string) $request->query('hash', '');
        $expires = $request->query('expires');
        $signature = (string) $request->query('signature', '');

        if (!$id || $hash === '' || !is_numeric($expires) || $signature === '') {
            return ['ok' => false, 'reason' => 'VERIFY_LINK_INVALID'];
        }

        $expiresAt = CarbonImmutable::createFromTimestampUTC((int) $expires);
        if ($expiresAt->isPast()) {
            return ['ok' => false, 'reason' => 'VERIFY_LINK_EXPIRED'];
        }

        $expectedSignedUrl = URL::temporarySignedRoute(
            'app.onboarding.verify-email',
            $expiresAt,
            [
                'id' => $id,
                'hash' => $hash,
            ],
            absolute: false
        );

        parse_str((string) parse_url($expectedSignedUrl, PHP_URL_QUERY), $expectedQuery);
        $expectedSignature = (string) ($expectedQuery['signature'] ?? '');

        if ($expectedSignature === '' || !hash_equals($expectedSignature, $signature)) {
            return ['ok' => false, 'reason' => 'VERIFY_LINK_INVALID'];
        }

        /** @var User|null $user */
        $user = User::query()->find($id);
        if (!$user) {
            return ['ok' => false, 'reason' => 'VERIFY_LINK_INVALID'];
        }

        if (!hash_equals(sha1((string) $user->email), $hash)) {
            return ['ok' => false, 'reason' => 'VERIFY_LINK_INVALID'];
        }

        if ($user->email_verified_at !== null) {
            return ['ok' => true, 'user' => $user, 'already_verified' => true];
        }

        $user->forceFill(['email_verified_at' => now()])->save();

        return ['ok' => true, 'user' => $user, 'already_verified' => false];
    }
}
