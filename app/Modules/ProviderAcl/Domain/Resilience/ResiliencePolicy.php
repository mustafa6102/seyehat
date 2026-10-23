<?php

namespace App\Modules\ProviderAcl\Domain\Resilience;

/**
 * CFG-RETRY-TIMEOUT-CIRCUIT (NFR-RL-001, ADR-006): provider-scoped resilience policy.
 * Numeric values are OPEN in the SSOT; a policy only exists when every value is explicitly configured.
 */
final readonly class ResiliencePolicy
{
    public function __construct(
        public string $version,
        public int $timeoutMs,
        public int $maxAttempts,
        public int $baseDelayMs,
        public int $maxDelayMs,
        public int $failureThreshold,
        public int $openSeconds,
        public int $rateLimitMaxRequests,
        public int $rateLimitPerSeconds,
    ) {}

    /** @param array<string, mixed>|null $config */
    public static function fromConfig(?array $config): ?self
    {
        $version = $config['version'] ?? null;
        $values = [
            $config['timeout_ms'] ?? null,
            $config['retry']['max_attempts'] ?? null,
            $config['retry']['base_delay_ms'] ?? null,
            $config['retry']['max_delay_ms'] ?? null,
            $config['circuit_breaker']['failure_threshold'] ?? null,
            $config['circuit_breaker']['open_seconds'] ?? null,
            $config['rate_limit']['max_requests'] ?? null,
            $config['rate_limit']['per_seconds'] ?? null,
        ];

        if (! is_string($version) || $version === '') {
            return null;
        }

        foreach ($values as $value) {
            if (! is_int($value) || $value < 1) {
                return null;
            }
        }

        return new self($version, ...$values);
    }

    /** Exponential backoff ceiling for full jitter: min(max, base * 2^(attempt-1)). */
    public function backoffCeilingMs(int $attempt): int
    {
        return (int) min($this->maxDelayMs, $this->baseDelayMs * (2 ** max(0, $attempt - 1)));
    }
}
