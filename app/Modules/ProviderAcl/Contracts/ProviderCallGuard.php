<?php

namespace App\Modules\ProviderAcl\Contracts;

/**
 * Public ProviderAcl service for resilient provider calls (PLT-REL-001): provider-scoped timeout,
 * retry budget with backoff + jitter, circuit breaker and rate limit.
 */
interface ProviderCallGuard
{
    /**
     * @template T
     *
     * @param  callable(int): T  $operation  receives the provider timeout in milliseconds
     * @return T
     *
     * @throws ProviderFailure
     */
    public function call(string $providerId, callable $operation): mixed;
}
