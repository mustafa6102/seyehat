<?php

namespace App\Modules\Authorization\Contracts;

use InvalidArgumentException;

/**
 * SCH-PrincipalRef (v3.16, ADR-010): the authenticated principal = (iss, sub) of a validated OIDC access token. INTERNAL
 * authorization data only - never part of a public response, plan snapshot, outbox payload or log line. Comparison is
 * exact and case-sensitive on both parts; e-mail, name, Traveler or request-supplied identities are never principals.
 */
final readonly class PrincipalRef
{
    public function __construct(public string $issuer, public string $subject)
    {
        foreach ([$issuer, $subject] as $part) {
            if ($part === '' || strlen($part) > 255) {
                throw new InvalidArgumentException('PrincipalRef parts must be 1-255 characters.');
            }
        }
    }

    public function equals(self $other): bool
    {
        return $this->issuer === $other->issuer && $this->subject === $other->subject;
    }
}
