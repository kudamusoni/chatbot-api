<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\UpdateDomainsRequest;
use App\Http\Requests\App\UpdateSettingsRequest;
use App\Models\Client;
use App\Models\ClientSetting;
use App\Services\AuditLogger;
use App\Support\CurrentClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function show(): JsonResponse
    {
        /** @var CurrentClient $currentClient */
        $currentClient = app(CurrentClient::class);
        $client = $currentClient->client;
        $settings = ClientSetting::forClientOrCreate((string) $currentClient->id());

        return response()->json($this->settingsPayload($client, $settings));
    }

    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        /** @var CurrentClient $currentClient */
        $currentClient = app(CurrentClient::class);
        /** @var Client $client */
        $client = $currentClient->client;
        $settings = ClientSetting::forClientOrCreate((string) $currentClient->id());

        $before = [
            'client_name' => $client->name,
            'settings' => $this->normalizedSettings($settings),
        ];

        $validated = $request->validated();
        $clientData = $validated['client'] ?? [];
        if (array_key_exists('name', $clientData) && $clientData['name'] !== null) {
            $client->name = (string) $clientData['name'];
            $client->save();
        }

        $settingsData = $validated['settings'] ?? [];
        // Locked allowlist for forward-compatible unknown key ignore.
        $allowlist = ['bot_name', 'brand_color', 'accent_color', 'logo_url', 'prompt_settings'];
        $filtered = array_intersect_key($settingsData, array_flip($allowlist));

        foreach ($filtered as $key => $value) {
            // v1 strategy: replace entire prompt_settings object when provided.
            if ($key === 'prompt_settings') {
                $settings->prompt_settings = $value ?? [];
                continue;
            }

            $settings->{$key} = $value;
        }
        $settings->save();

        /** @var \App\Models\User $actor */
        $actor = $request->user();
        $this->auditLogger->log($actor, 'client.settings.updated', $client->id, [
            'before' => $before,
            'after' => [
                'client_name' => $client->name,
                'settings' => $this->normalizedSettings($settings),
            ],
            'ip' => $request->ip(),
            'ua' => (string) $request->userAgent(),
        ]);

        return response()->json($this->settingsPayload($client, $settings));
    }

    public function updateDomains(UpdateDomainsRequest $request): JsonResponse
    {
        /** @var CurrentClient $currentClient */
        $currentClient = app(CurrentClient::class);
        $clientId = (string) $currentClient->id();

        $normalized = [];
        $errors = [];

        /** @var array<int, string> $origins */
        $origins = $request->validated('allowed_origins', []);
        foreach ($origins as $idx => $origin) {
            $result = $this->normalizeOrigin($origin);
            if ($result['error'] !== null) {
                $errors["allowed_origins.{$idx}"][] = $result['error'];
                continue;
            }
            $normalized[] = $result['value'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $normalized = array_values(array_unique($normalized));

        DB::table('client_settings')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'client_id' => $clientId,
            'widget_security_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = DB::transaction(function () use ($clientId, $normalized): array {
            /** @var ClientSetting|null $settings */
            $settings = ClientSetting::query()
                ->where('client_id', $clientId)
                ->lockForUpdate()
                ->first();

            if (!$settings) {
                // Defensive fallback, updateOrInsert above should ensure a row exists.
                $settings = ClientSetting::create([
                    'client_id' => $clientId,
                    'widget_security_version' => 1,
                ]);
                $settings = ClientSetting::query()->where('id', $settings->id)->lockForUpdate()->first();
            }

            $before = is_array($settings->allowed_origins) ? $settings->allowed_origins : [];
            $settings->allowed_origins = $normalized;
            $settings->widget_security_version = ((int) $settings->widget_security_version) + 1;
            $settings->save();

            return [
                'before' => $before,
                'version' => (int) $settings->widget_security_version,
                'allowed_origins' => $settings->allowed_origins ?? [],
            ];
        });

        /** @var \App\Models\User $actor */
        $actor = $request->user();
        $this->auditLogger->log($actor, 'client.domains.updated', $clientId, [
            'before' => $result['before'],
            'after' => $result['allowed_origins'],
            'ip' => $request->ip(),
            'ua' => (string) $request->userAgent(),
        ]);

        return response()->json([
            'ok' => true,
            'widget_security_version' => $result['version'],
            'allowed_origins' => $result['allowed_origins'],
        ]);
    }

    /**
     * @return array{value:string|null,error:string|null}
     */
    private function normalizeOrigin(string $origin): array
    {
        $raw = trim($origin);

        if ($raw === '' || str_contains($raw, '*')) {
            return ['value' => null, 'error' => 'Wildcard origins are not supported.'];
        }

        $parts = parse_url($raw);
        if (!$parts || !isset($parts['scheme'], $parts['host'])) {
            return ['value' => null, 'error' => 'Origin must include scheme and host only.'];
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return ['value' => null, 'error' => 'Origin must not include auth, query, or fragment.'];
        }

        $path = $parts['path'] ?? '';
        if ($path !== '' && $path !== '/') {
            return ['value' => null, 'error' => 'Origin path is not allowed.'];
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        if (str_contains($host, '*')) {
            return ['value' => null, 'error' => 'Wildcard hosts are not supported.'];
        }

        if ($scheme === 'http') {
            $isLocal = app()->environment(['local', 'testing']);
            $localhostHosts = ['localhost', '127.0.0.1', '::1'];
            if (!($isLocal && in_array($host, $localhostHosts, true))) {
                return ['value' => null, 'error' => 'Only HTTPS origins are allowed in this environment.'];
            }
        } elseif ($scheme !== 'https') {
            return ['value' => null, 'error' => 'Origin scheme must be HTTPS (or localhost HTTP in local/testing).'];
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if ($port === 443 && $scheme === 'https') {
            $port = null;
        }
        if ($port === 80 && $scheme === 'http') {
            $port = null;
        }

        $normalized = "{$scheme}://{$host}";
        if ($port !== null) {
            $normalized .= ':' . $port;
        }

        return ['value' => $normalized, 'error' => null];
    }

    private function settingsPayload(Client $client, ClientSetting $settings): array
    {
        return [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
            ],
            'settings' => $this->normalizedSettings($settings),
        ];
    }

    private function normalizedSettings(ClientSetting $settings): array
    {
        return [
            'bot_name' => $settings->bot_name,
            'brand_color' => $settings->brand_color,
            'accent_color' => $settings->accent_color,
            'logo_url' => $settings->logo_url,
            'prompt_settings' => is_array($settings->prompt_settings) ? $settings->prompt_settings : [],
            'allowed_origins' => is_array($settings->allowed_origins) ? $settings->allowed_origins : [],
            'widget_security_version' => (int) ($settings->widget_security_version ?? 1),
        ];
    }
}
