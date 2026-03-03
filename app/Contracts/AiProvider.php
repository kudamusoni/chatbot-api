<?php

namespace App\Contracts;

use App\Services\Ai\AiJsonResult;
use App\Services\Ai\AiResult;

interface AiProvider
{
    /**
     * @param array<int, array{role:string,content:string}> $messages
     * @param array<string, mixed> $options
     */
    public function chat(array $messages, array $options = []): AiResult;

    /**
     * @param array<int, array{role:string,content:string}> $messages
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $options
     */
    public function json(array $messages, array $schema, array $options = []): AiJsonResult;
}

