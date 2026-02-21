<?php

namespace App\Support;

use App\Models\Client;

class CurrentClient
{
    public function __construct(
        public readonly ?Client $client,
        public readonly ?string $role,
        public readonly bool $impersonating = false
    ) {}

    public function id(): ?string
    {
        return $this->client?->id;
    }

    public function isSet(): bool
    {
        return $this->client !== null;
    }
}
