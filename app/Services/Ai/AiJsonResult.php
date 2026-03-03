<?php

namespace App\Services\Ai;

class AiJsonResult
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly array $data,
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        public readonly ?int $costEstimateMinor = null
    ) {}
}

