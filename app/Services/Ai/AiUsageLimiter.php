<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Cache;

class AiUsageLimiter
{
    /**
     * @return array{allowed:bool,reason:?string}
     */
    public function allow(string $clientId, ?int $costMinor = null): array
    {
        if ($this->isCircuitOpen($clientId)) {
            return ['allowed' => false, 'reason' => 'CIRCUIT_OPEN'];
        }

        $minuteCap = (int) config('ai.usage.max_requests_per_minute_per_client', 30);
        $dailyCostCap = (int) config('ai.usage.daily_cost_cap_minor_per_client', 5000);
        $dailyRequestCap = (int) config('ai.usage.daily_request_cap_per_client', 2000);

        $minuteKey = "ai:usage:{$clientId}:m:" . now()->utc()->format('YmdHi');
        $dayKey = "ai:usage:{$clientId}:d:" . now()->utc()->format('Ymd');
        $dayCostKey = "ai:usage:{$clientId}:c:" . now()->utc()->format('Ymd');

        $minuteCount = (int) Cache::increment($minuteKey);
        Cache::put($minuteKey, $minuteCount, now()->addMinutes(2));

        if ($minuteCount > $minuteCap) {
            return ['allowed' => false, 'reason' => 'RATE_LIMITED'];
        }

        $dayCount = (int) Cache::increment($dayKey);
        Cache::put($dayKey, $dayCount, now()->addDay());

        if ($costMinor === null) {
            if ($dayCount > $dailyRequestCap) {
                return ['allowed' => false, 'reason' => 'COST_CAPPED'];
            }

            return ['allowed' => true, 'reason' => null];
        }

        $dayCost = (int) Cache::increment($dayCostKey, $costMinor);
        Cache::put($dayCostKey, $dayCost, now()->addDay());

        if ($dayCost > $dailyCostCap) {
            return ['allowed' => false, 'reason' => 'COST_CAPPED'];
        }

        return ['allowed' => true, 'reason' => null];
    }

    public function recordSuccess(string $clientId): void
    {
        Cache::forget($this->clientFailureKey($clientId));
    }

    public function recordFailure(string $clientId, string $errorCode = 'AI_PROVIDER_ERROR'): void
    {
        if (!config('ai.circuit.enabled', true)) {
            return;
        }

        if (!$this->countsTowardCircuit($errorCode)) {
            return;
        }

        $clientFailures = (int) Cache::increment($this->clientFailureKey($clientId));
        Cache::put($this->clientFailureKey($clientId), $clientFailures, now()->addMinutes(10));

        if ($clientFailures >= (int) config('ai.circuit.failure_threshold', 5)) {
            Cache::put(
                $this->clientCircuitKey($clientId),
                1,
                now()->addSeconds((int) config('ai.circuit.cooldown_seconds', 120))
            );
            Cache::forget($this->clientFailureKey($clientId));
        }

        $globalFailures = (int) Cache::increment($this->globalFailureKey());
        Cache::put($this->globalFailureKey(), $globalFailures, now()->addMinutes(10));

        if ($globalFailures >= (int) config('ai.circuit.global_failure_threshold', 30)) {
            Cache::put(
                $this->globalCircuitKey(),
                1,
                now()->addSeconds((int) config('ai.circuit.global_cooldown_seconds', 120))
            );
            Cache::forget($this->globalFailureKey());
        }
    }

    private function isCircuitOpen(string $clientId): bool
    {
        if (!config('ai.circuit.enabled', true)) {
            return false;
        }

        return Cache::has($this->clientCircuitKey($clientId))
            || Cache::has($this->globalCircuitKey());
    }

    private function clientCircuitKey(string $clientId): string
    {
        return "ai:circuit_open:{$clientId}";
    }

    private function globalCircuitKey(): string
    {
        return 'ai:circuit_open:global';
    }

    private function clientFailureKey(string $clientId): string
    {
        return "ai:circuit_failures:{$clientId}";
    }

    private function globalFailureKey(): string
    {
        return 'ai:circuit_failures:global';
    }

    private function countsTowardCircuit(string $errorCode): bool
    {
        $healthCodes = [
            'AI_TIMEOUT',
            'AI_PROVIDER_ERROR',
            'AI_RATE_LIMITED',
            'NETWORK_ERROR',
        ];

        return in_array($errorCode, $healthCodes, true);
    }
}
