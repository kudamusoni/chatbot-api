<?php

namespace App\Services\Ai;

use RuntimeException;

class AiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message
    ) {
        parent::__construct($message);
    }
}

