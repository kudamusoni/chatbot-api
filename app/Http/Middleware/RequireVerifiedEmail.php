<?php

namespace App\Http\Middleware;

use App\Enums\AppDenyReason;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireVerifiedEmail
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'error' => 'UNAUTHENTICATED',
                'reason_code' => AppDenyReason::UNAUTHENTICATED->value,
            ], 401);
        }

        if ($user->email_verified_at === null) {
            return response()->json([
                'error' => 'FORBIDDEN',
                'reason_code' => AppDenyReason::EMAIL_NOT_VERIFIED->value,
            ], 403);
        }

        return $next($request);
    }
}

