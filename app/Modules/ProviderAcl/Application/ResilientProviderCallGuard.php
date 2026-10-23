<?php

namespace App\Modules\ProviderAcl\Application;

use App\Modules\ProviderAcl\Contracts\ProviderCallGuard;
use App\Modules\ProviderAcl\Contracts\ProviderFailure;
use App\Modules\ProviderAcl\Contracts\ProviderFailureKind;
use App\Modules\ProviderAcl\Contracts\ResiliencePolicies;
use App\Modules\ProviderAcl\Domain\Resilience\CircuitState;
use App\Modules\ProviderAcl\Domain\Resilience\ResiliencePolicy;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Applies the provider-scoped timeout, retry budget (exponential backoff + full jitter), circuit breaker
 * and rate limit (RULE-PLT-REL-001-01/03). Every failure leaves as a normalized ProviderFailure.
 */
final readonly class ResilientProviderCallGuard implements ProviderCallGuard
{
    public function __construct(
        private ResiliencePolicies $policies,
        private CircuitBreaker $breaker,
        private RateLimiter $limiter,
        private ?Closure $jitter = null,
    ) {}

    /**
     * @template T
     *
     * @param  callable(int): T  $operation  receives the provider timeout in milliseconds
     * @return T
     *
     * @throws ProviderFailure
     */
    public function call(string $providerId, callable $operation): mixed
    {
        $policy = $this->policies->for($providerId);
        if ($policy === null) {
            $this->signal($providerId, ProviderFailureKind::NOT_CONFIGURED, 0);

            throw ProviderFailure::of(ProviderFailureKind::NOT_CONFIGURED);
        }

        if (! $this->breaker->allowsCall($providerId, $policy)) {
            $this->signal($providerId, ProviderFailureKind::CIRCUIT_OPEN, 0);

            throw ProviderFailure::of(ProviderFailureKind::CIRCUIT_OPEN);
        }

        for ($attempt = 1; ; $attempt++) {
            if (! $this->acquireRateSlot($providerId, $policy)) {
                // Local limit: the provider was not called, so the circuit is not charged.
                $this->signal($providerId, ProviderFailureKind::RATE_LIMITED, $attempt);

                throw ProviderFailure::rateLimited();
            }

            $startedAt = hrtime(true);
            try {
                $result = $operation($policy->timeoutMs);
                if ((hrtime(true) - $startedAt) / 1_000_000 > $policy->timeoutMs) {
                    // A late answer is outside the budget and is discarded, never used as data.
                    throw ProviderFailure::timeout();
                }
                $this->breaker->recordSuccess($providerId);

                return $result;
            } catch (Throwable $error) {
                $failure = ProviderFailure::classify($error);
            }

            $this->breaker->recordFailure($providerId, $policy);
            $this->signal($providerId, $failure->kind, $attempt);

            if (! $failure->kind->retryable()
                || $attempt >= $policy->maxAttempts
                || $this->breaker->state($providerId, $policy) !== CircuitState::CLOSED) {
                throw $failure;
            }

            Sleep::usleep($this->jitterMs($policy->backoffCeilingMs($attempt)) * 1000);
        }
    }

    private function acquireRateSlot(string $providerId, ResiliencePolicy $policy): bool
    {
        return $this->limiter->attempt(
            'provider-resilience:rate:'.$providerId,
            $policy->rateLimitMaxRequests,
            fn (): bool => true,
            $policy->rateLimitPerSeconds,
        );
    }

    private function jitterMs(int $ceilingMs): int
    {
        return $this->jitter === null ? random_int(0, $ceilingMs) : ($this->jitter)($ceilingMs);
    }

    private function signal(string $providerId, ProviderFailureKind $kind, int $attempt): void
    {
        Log::warning('provider.call.failed', ['provider_id' => $providerId, 'failure' => $kind->value, 'attempt' => $attempt]);
    }
}
