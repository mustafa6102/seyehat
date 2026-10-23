<?php

namespace App\Modules\ProviderAcl\Contracts;

use RuntimeException;
use Throwable;

/**
 * Normalized provider failure. Carries only a fixed Turkish message; raw vendor errors are never chained
 * or copied so they cannot reach users, job payloads or logs (RULE-PLT-REL-001-04).
 */
final class ProviderFailure extends RuntimeException
{
    private function __construct(public readonly ProviderFailureKind $kind)
    {
        parent::__construct($kind->messageTr());
    }

    public static function of(ProviderFailureKind $kind): self
    {
        return new self($kind);
    }

    public static function timeout(): self
    {
        return new self(ProviderFailureKind::TIMEOUT);
    }

    /** HTTP 429 from the provider. */
    public static function rateLimited(): self
    {
        return new self(ProviderFailureKind::RATE_LIMITED);
    }

    /** HTTP 5xx or transport failure. */
    public static function unavailable(): self
    {
        return new self(ProviderFailureKind::UNAVAILABLE);
    }

    public static function classify(Throwable $error): self
    {
        return $error instanceof self ? $error : new self(ProviderFailureKind::UNAVAILABLE);
    }
}
