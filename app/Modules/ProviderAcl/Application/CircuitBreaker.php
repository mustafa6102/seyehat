<?php

namespace App\Modules\ProviderAcl\Application;

use App\Modules\ProviderAcl\Domain\Resilience\CircuitState;
use App\Modules\ProviderAcl\Domain\Resilience\ResiliencePolicy;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Log;

/**
 * Provider-scoped circuit breaker (RULE-PLT-REL-001-01). State lives in the shared cache so every
 * worker sees the same circuit; one provider's circuit never affects another provider.
 */
final readonly class CircuitBreaker
{
    public function __construct(private Repository $cache) {}

    public function state(string $providerId, ResiliencePolicy $policy): CircuitState
    {
        $openedAt = $this->read($providerId)['opened_at_ms'];

        if ($openedAt === null) {
            return CircuitState::CLOSED;
        }

        return $this->nowMs() >= $openedAt + $policy->openSeconds * 1000 ? CircuitState::HALF_OPEN : CircuitState::OPEN;
    }

    /** In HALF_OPEN exactly one probe call is admitted until it succeeds or reopens the circuit. */
    public function allowsCall(string $providerId, ResiliencePolicy $policy): bool
    {
        return match ($this->state($providerId, $policy)) {
            CircuitState::CLOSED => true,
            CircuitState::OPEN => false,
            CircuitState::HALF_OPEN => $this->cache->add($this->key($providerId).':probe', true, $policy->openSeconds),
        };
    }

    public function recordSuccess(string $providerId): void
    {
        $wasOpen = $this->read($providerId)['opened_at_ms'] !== null;
        $this->cache->forget($this->key($providerId));
        $this->cache->forget($this->key($providerId).':probe');

        if ($wasOpen) {
            $this->signal($providerId, CircuitState::CLOSED);
        }
    }

    public function recordFailure(string $providerId, ResiliencePolicy $policy): void
    {
        $current = $this->read($providerId);

        if ($current['opened_at_ms'] !== null) {
            // A failed HALF_OPEN probe (or a late failure while OPEN) restarts the open period.
            $this->write($providerId, $current['failures'] + 1, $this->nowMs());
            $this->cache->forget($this->key($providerId).':probe');
            $this->signal($providerId, CircuitState::OPEN);

            return;
        }

        $failures = $current['failures'] + 1;
        $opened = $failures >= $policy->failureThreshold;
        $this->write($providerId, $failures, $opened ? $this->nowMs() : null);

        if ($opened) {
            $this->signal($providerId, CircuitState::OPEN);
        }
    }

    /** @return array{failures: int, opened_at_ms: int|null} */
    private function read(string $providerId): array
    {
        $value = $this->cache->get($this->key($providerId));

        return [
            'failures' => is_int($value['failures'] ?? null) ? $value['failures'] : 0,
            'opened_at_ms' => is_int($value['opened_at_ms'] ?? null) ? $value['opened_at_ms'] : null,
        ];
    }

    private function write(string $providerId, int $failures, ?int $openedAtMs): void
    {
        $this->cache->forever($this->key($providerId), ['failures' => $failures, 'opened_at_ms' => $openedAtMs]);
    }

    private function key(string $providerId): string
    {
        return 'provider-resilience:circuit:'.$providerId;
    }

    private function nowMs(): int
    {
        return (int) now()->getPreciseTimestamp(3);
    }

    /** SLI signal (FR-PLT-REL-001-01): provider id and state only, never vendor payloads. */
    private function signal(string $providerId, CircuitState $state): void
    {
        Log::warning('provider.circuit.state_changed', ['provider_id' => $providerId, 'circuit_state' => $state->value]);
    }
}
