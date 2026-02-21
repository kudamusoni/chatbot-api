<?php

namespace App\Http\Middleware;

use App\Enums\AppDenyReason;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePlatformRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user || !$user->isPlatformAdmin()) {
            return response()->json([
                'error' => 'FORBIDDEN',
                'reason_code' => AppDenyReason::PLATFORM_ROLE_REQUIRED->value,
            ], 403);
        }

        if ($roles !== [] && !in_array($user->platform_role, $roles, true)) {
            return response()->json([
                'error' => 'FORBIDDEN',
                'reason_code' => AppDenyReason::INSUFFICIENT_ROLE->value,
            ], 403);
        }

        return $next($request);
    }
}
