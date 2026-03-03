<?php

namespace App\Http\Controllers\App;

use App\Enums\AppDenyReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\LoginRequest;
use App\Http\Requests\App\UpdateProfileRequest;
use App\Services\AppBootPayloadService;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function __construct(
        private readonly AppBootPayloadService $bootPayloadService,
        private readonly AuditLogger $auditLogger
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        if (!Auth::attempt($credentials)) {
            return response()->json([
                'error' => 'UNAUTHENTICATED',
                'reason_code' => AppDenyReason::UNAUTHENTICATED->value,
            ], 401);
        }

        $request->session()->regenerate();

        /** @var \App\Models\User $user */
        $user = $request->user();

        if (!$user->isPlatformAdmin()) {
            $clientIds = $user->clients()->pluck('clients.id');
            if ($clientIds->count() === 1) {
                $request->session()->put('active_client_id', $clientIds->first());
            }
        }

        return response()->json($this->bootPayloadService->forUser($request, $user));
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        return response()->json($this->bootPayloadService->forUser($request, $user));
    }

    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $before = ['name' => $user->name];
        $user->name = $request->validated()['name'];
        $user->save();

        $this->auditLogger->log($user, 'user.profile.updated', null, [
            'before' => $before,
            'after' => ['name' => $user->name],
            'ip' => $request->ip(),
            'ua' => (string) $request->userAgent(),
        ]);

        return response()->json($this->bootPayloadService->forUser($request, $user));
    }
}
