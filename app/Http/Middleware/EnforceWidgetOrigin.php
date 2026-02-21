<?php

namespace App\Http\Middleware;

use App\Enums\WidgetDenyReason;
use App\Models\Client;
use App\Models\Conversation;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnforceWidgetOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldBypass()) {
            return $next($request);
        }

        $client = $this->resolveClient($request);

        if (!$client) {
            return $this->deny($request, WidgetDenyReason::ORIGIN_MISMATCH);
        }

        [$securityVersion, $allowedOrigins] = $this->resolveWidgetSettings($client);
        $origin = $this->extractRequestOrigin($request);

        if ($origin !== null) {
            if (!in_array($origin, $allowedOrigins, true)) {
                return $this->deny($request, WidgetDenyReason::ORIGIN_MISMATCH, $client->id, $securityVersion);
            }

            $this->logAllowed($request, $client->id, $securityVersion);

            return $next($request);
        }

        if ($this->isBootstrap($request)) {
            return $this->deny($request, WidgetDenyReason::ORIGIN_MISSING_BOOTSTRAP, $client->id, $securityVersion);
        }

        if (!$this->hasRecentValidSession($request, $client->id)) {
            return $this->deny($request, WidgetDenyReason::STALE_SESSION_NO_ORIGIN, $client->id, $securityVersion);
        }

        $this->logAllowed($request, $client->id, $securityVersion);

        return $next($request);
    }

    private function shouldBypass(): bool
    {
        return (bool) config('widget.security.bypass_local_origin_checks', false)
            && app()->environment(['local', 'testing']);
    }

    private function resolveClient(Request $request): ?Client
    {
        $clientId = $request->input('client_id')
            ?? $request->query('client_id')
            ?? $request->header('X-Client-Id');

        if (!is_string($clientId) || trim($clientId) === '') {
            return null;
        }

        return Client::find($clientId);
    }

    /**
     * Source of truth: client_settings table.
     * Transitional fallback: clients.settings for migration window only.
     *
     * Removal gate:
     * remove clients.settings fallback after 24h stable deploy
     * with no origin-related errors in logs.
     *
     * @return array{0: int, 1: array<int, string>}
     */
    private function resolveWidgetSettings(Client $client): array
    {
        $settings = $client->clientSetting;

        if ($settings) {
            return [
                (int) ($settings->widget_security_version ?? 1),
                $this->normalizeAllowedOrigins($settings->allowed_origins ?? []),
            ];
        }

        return [
            (int) (($client->settings['widget_security_version'] ?? 1)),
            $this->normalizeAllowedOrigins($client->settings['allowed_origins'] ?? []),
        ];
    }

    private function normalizeAllowedOrigins(array $origins): array
    {
        $normalized = [];

        foreach ($origins as $origin) {
            if (!is_string($origin)) {
                continue;
            }

            $value = $this->normalizeOrigin($origin);
            if ($value !== null) {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function extractRequestOrigin(Request $request): ?string
    {
        $originHeader = $request->headers->get('Origin');
        if (is_string($originHeader) && trim($originHeader) !== '') {
            return $this->normalizeOrigin($originHeader);
        }

        $referer = $request->headers->get('Referer');
        if (!is_string($referer) || trim($referer) === '') {
            return null;
        }

        $parts = parse_url($referer);
        if (!$parts || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $this->normalizeOrigin($origin);
    }

    private function normalizeOrigin(string $origin): ?string
    {
        $parts = parse_url(trim($origin));
        if (!$parts || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return "{$scheme}://{$host}{$port}";
    }

    private function isBootstrap(Request $request): bool
    {
        return $request->is('api/widget/bootstrap');
    }

    private function hasRecentValidSession(Request $request, string $clientId): bool
    {
        $sessionToken = $request->input('session_token')
            ?? $request->query('session_token')
            ?? $request->header('X-Session-Token');

        if (!is_string($sessionToken) || trim($sessionToken) === '') {
            return false;
        }

        $conversation = Conversation::findByTokenForClient($sessionToken, $clientId);
        if (!$conversation || !$conversation->last_activity_at) {
            return false;
        }

        $maxIdleHours = (int) config('widget.security.no_origin_max_idle_hours', 24);

        return $conversation->last_activity_at->greaterThanOrEqualTo(
            Carbon::now()->subHours($maxIdleHours)
        );
    }

    private function deny(
        Request $request,
        WidgetDenyReason $reason,
        ?string $clientId = null,
        ?int $securityVersion = null
    ): JsonResponse {
        Log::warning('Widget request denied', [
            'reason_code' => $reason->value,
            'client_id' => $clientId,
            'widget_security_version' => $securityVersion,
            'path' => $request->path(),
            'method' => $request->method(),
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'error' => 'Origin not allowed for this client',
            'reason_code' => $reason->value,
        ], 403);
    }

    private function logAllowed(Request $request, string $clientId, int $securityVersion): void
    {
        $sessionToken = $request->input('session_token')
            ?? $request->query('session_token')
            ?? $request->header('X-Session-Token');

        $sessionTokenHash = is_string($sessionToken) && $sessionToken !== ''
            ? Conversation::hashSessionToken($sessionToken)
            : null;

        Log::info('Widget request allowed', [
            'client_id' => $clientId,
            'widget_security_version' => $securityVersion,
            'path' => $request->path(),
            'method' => $request->method(),
            'session_token_hash' => $sessionTokenHash,
            'ip' => $request->ip(),
        ]);
    }
}
