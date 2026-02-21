<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

class DashboardRange
{
    public function __construct(
        public readonly string $label,
        public readonly ?CarbonImmutable $from,
        public readonly CarbonImmutable $to,
    ) {}

    public static function parse(string $range, bool $timeseriesAllCapped = false): self
    {
        $label = strtolower(trim($range));
        $now = CarbonImmutable::now('UTC');

        return match ($label) {
            'today' => new self('today', $now->startOfDay(), $now),
            '7d' => new self('7d', $now->subDays(7), $now),
            '30d' => new self('30d', $now->subDays(30), $now),
            'all' => new self('all', $timeseriesAllCapped ? $now->subDays(30) : null, $now),
            default => throw new InvalidArgumentException('Unsupported range value.'),
        };
    }

    public function fromIso(): ?string
    {
        return $this->from?->format('Y-m-d\\TH:i:s\\Z');
    }

    public function toIso(): string
    {
        return $this->to->format('Y-m-d\\TH:i:s\\Z');
    }

    public function dayKeys(): array
    {
        if ($this->from === null) {
            return [];
        }

        $keys = [];
        $cursor = $this->from->startOfDay();
        $end = $this->to->startOfDay();

        while ($cursor->lessThanOrEqualTo($end)) {
            $keys[] = $cursor->format('Y-m-d');
            $cursor = $cursor->addDay();
        }

        return $keys;
    }
}
