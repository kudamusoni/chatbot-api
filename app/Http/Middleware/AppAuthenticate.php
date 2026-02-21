<?php

namespace App\Http\Middleware;

use App\Enums\AppDenyReason;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AppAuthenticate
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user()) {
            return response()->json([
                'error' => 'UNAUTHENTICATED',
                'reason_code' => AppDenyReason::UNAUTHENTICATED->value,
            ], 401);
        }

        return $next($request);
    }
}
