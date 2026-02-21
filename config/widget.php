<?php

$appEnv = env('APP_ENV', 'production');
$defaultSseConnections = match ($appEnv) {
    'local', 'development' => 10,
    'staging' => 3,
    default => 2,
};

return [
    'script_url' => (string) env('WIDGET_SCRIPT_URL', rtrim((string) env('APP_URL', ''), '/') . '/widget.js'),
    'security' => [
        'bypass_local_origin_checks' => (bool) env('WIDGET_ORIGIN_CHECK_BYPASS_LOCAL', true),
        'no_origin_max_idle_hours' => (int) env('WIDGET_NO_ORIGIN_MAX_IDLE_HOURS', 24),
    ],
    'sse' => [
        'replay_max_events' => (int) env('SSE_REPLAY_MAX_EVENTS', 500),
        'replay_max_age_seconds' => (int) env('SSE_REPLAY_MAX_AGE_SECONDS', 3600),
        'max_connections_per_session' => (int) env('SSE_MAX_CONNECTIONS_PER_SESSION', $defaultSseConnections),
        'connection_ttl_seconds' => (int) env('SSE_CONNECTION_TTL_SECONDS', 60),
    ],
];
