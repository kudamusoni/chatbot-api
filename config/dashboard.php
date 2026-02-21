<?php

return [
    'pagination' => [
        'default_per_page' => 20,
        'max_per_page' => 100,
    ],

    // Canonical default sorting per dashboard list endpoint.
    'sorts' => [
        'leads' => ['column' => 'leads.created_at', 'direction' => 'desc'],
        'catalog_imports' => ['column' => 'catalog_imports.created_at', 'direction' => 'desc'],
        'conversations' => ['column' => 'conversations.last_activity_at', 'direction' => 'desc'],
        'valuations' => ['column' => 'valuations.created_at', 'direction' => 'desc'],
        'appraisal_questions' => ['column' => 'appraisal_questions.order_index', 'direction' => 'asc'],
    ],
];
