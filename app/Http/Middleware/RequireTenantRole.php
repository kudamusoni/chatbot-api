<?php

namespace App\Http\Middleware;

use App\Enums\AppDenyReason;
use App\Support\CurrentClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireTenantRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        /** @var CurrentClient|null $current */
        $current = app()->bound(CurrentClient::class)
            ? app(CurrentClient::class)
            : null;

        if (!$current || !$current->isSet()) {
            return response()->json([
                'error' => 'CONFLICT',
                'reason_code' => AppDenyReason::NO_ACTIVE_CLIENT->value,
            ], 409);
        }

        $user = $request->user();
        if (!$user) {
            return response()->json([
                'error' => 'UNAUTHENTICATED',
                'reason_code' => AppDenyReason::UNAUTHENTICATED->value,
            ], 401);
        }

        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        if ($user->isSupportAdmin()) {
            return response()->json([
                'error' => 'FORBIDDEN',
                'reason_code' => AppDenyReason::INSUFFICIENT_ROLE->value,
            ], 403);
        }

        if ($roles !== [] && !in_array((string) $current->role, $roles, true)) {
            return response()->json([
                'error' => 'FORBIDDEN',
                'reason_code' => AppDenyReason::INSUFFICIENT_ROLE->value,
            ], 403);
        }

        return $next($request);
    }
}
