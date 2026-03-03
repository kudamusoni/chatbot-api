<?php

return [
    // Single source of truth for strict preflight gating.
    'strict_keys' => ['maker', 'item_type', 'model'],

    // Confidence threshold for considering a normalized field resolved.
    'resolved_confidence_threshold' => 0.75,

    // User-entered values treated as intentional unknown/skip.
    'skip_tokens' => ['unknown', 'not sure', 'skip'],

    // Deterministic clarification prompts by strict key.
    'missing_prompts' => [
        'maker' => 'Before I can value it, I need the maker/brand. What is it?',
        'item_type' => 'Before I can value it, I need the item type. What kind of item is it?',
        'model' => 'Before I can value it, I need the model. Do you know it?',
    ],

    // Confidence caps when preflight cannot fully validate normalized structure.
    'confidence_caps' => [
        'skipped' => 0.5,
        'ai_failed' => 0.4,
        'non_strict_unresolved' => 0.7,
    ],
];
