<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\ClientSetting;
use App\Support\CurrentClient;
use Illuminate\Http\JsonResponse;

class EmbedCodeController extends Controller
{
    public function show(): JsonResponse
    {
        /** @var CurrentClient $currentClient */
        $currentClient = app(CurrentClient::class);
        $clientId = (string) $currentClient->id();
        $settings = ClientSetting::forClientOrCreate($clientId);
        $version = (int) ($settings->widget_security_version ?? 1);
        $scriptUrl = (string) config('widget.script_url');
        $apiUrl = (string) config('widget.api_url');

        $snippet = sprintf(
            '<script src="%s" defer data-auto-init data-client-id="%s" data-api-url="%s" data-widget-security-version="%d"></script>',
            e($scriptUrl),
            e($clientId),
            e($apiUrl),
            $version
        );

        return response()->json([
            'script_url' => $scriptUrl,
            'api_url' => $apiUrl,
            'params' => [
                'client_id' => $clientId,
                'api_url' => $apiUrl,
                'widget_security_version' => $version,
            ],
            'widget_security_version' => $version,
            'snippet' => $snippet,
        ]);
    }
}
