<?php

return [
    'enabled' => (bool) env('AI_ENABLED', false),
    'provider' => env('AI_PROVIDER', 'openai'),
    'models' => [
        'chat' => env('AI_MODEL_CHAT', 'gpt-4o-mini'),
        'normalize' => env('AI_MODEL_NORMALIZE', 'gpt-4o-mini'),
    ],
    'timeout_seconds' => (int) env('AI_TIMEOUT_SECONDS', 12),
    'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 300),
    'temperature' => (float) env('AI_TEMPERATURE', 0.2),
    'assistant_max_chars' => (int) env('AI_ASSISTANT_MAX_CHARS', 1200),
    'fallback' => [
        'assistant_message' => env('AI_FALLBACK_ASSISTANT_MESSAGE', "I'm having trouble right now. Can you rephrase that?"),
    ],
    'usage' => [
        'max_requests_per_minute_per_client' => (int) env('AI_MAX_REQUESTS_PER_MINUTE_PER_CLIENT', 30),
        'daily_cost_cap_minor_per_client' => (int) env('AI_DAILY_COST_CAP_MINOR_PER_CLIENT', 5000),
        'daily_request_cap_per_client' => (int) env('AI_DAILY_REQUEST_CAP_PER_CLIENT', 2000),
    ],
    'circuit' => [
        'enabled' => (bool) env('AI_CIRCUIT_ENABLED', true),
        'failure_threshold' => (int) env('AI_CIRCUIT_FAILURE_THRESHOLD', 5),
        'cooldown_seconds' => (int) env('AI_CIRCUIT_COOLDOWN_SECONDS', 120),
        'global_failure_threshold' => (int) env('AI_CIRCUIT_GLOBAL_FAILURE_THRESHOLD', 30),
        'global_cooldown_seconds' => (int) env('AI_CIRCUIT_GLOBAL_COOLDOWN_SECONDS', 120),
    ],
    'policy' => [
        'block_patterns' => [
            '/\b(i\s+(checked|searched|verified|looked up)\s+(the\s+)?(internet|web|online))\b/i',
            '/\b(as\s+an?\s+ai,\s*i\s+browsed)\b/i',
            '/\b(source|citation)s?:\s*https?:\/\//i',
            '/\baccording to (google|wikipedia|the web)\b/i',
        ],
    ],
    'prompt_versions' => [
        'chat' => 'chat:v1',
        'normalize' => 'normalize:v1',
    ],
    'policy_version' => env('AI_POLICY_VERSION'),
    'normalization' => [
        'keys' => ['maker', 'item_type', 'model', 'material', 'size', 'age'],
        'clarification_required_keys' => ['maker', 'item_type', 'model'],
        'confidence_threshold' => 0.75,
        'clarification_threshold' => 0.5,
    ],
    'openai' => [
        'api_key' => env('AI_OPENAI_API_KEY'),
        'base_url' => env('AI_OPENAI_BASE_URL', 'https://api.openai.com/v1'),
    ],
];
