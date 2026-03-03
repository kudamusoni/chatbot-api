<?php

namespace App\Services\Ai;

class AiResult
{
    public function __construct(
        public readonly string $content,
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        public readonly ?int $costEstimateMinor = null
    ) {}
}

