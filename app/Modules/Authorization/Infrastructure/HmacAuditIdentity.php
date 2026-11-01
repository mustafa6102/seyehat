<?php

namespace App\Modules\Authorization\Infrastructure;

use App\Modules\Authorization\Contracts\AuditIdentity;
use App\Modules\Authorization\Contracts\AuditIdentityUnavailable;
use App\Modules\Authorization\Contracts\PrincipalRef;

/**
 * HMAC-SHA256 with one label per value type, so a subject and a trip never share a pseudonym. There is no unkeyed fallback:
 * a plain hash of (iss, sub) could be recomputed by anyone who reads the log. Outside local and testing the key must be
 * dedicated, so APP_KEY itself is refused even when it is supplied under the audit key's name.
 */
final class HmacAuditIdentity implements AuditIdentity
{
    private const MIN_KEY_BYTES = 32;

    public function isAvailable(): bool
    {
        return $this->key() !== null;
    }

    public function subjectId(PrincipalRef $principal): string
    {
        // The issuer length keeps ("ab", "c") and ("a", "bc") apart.
        return $this->pseudonym('subject-v1', strlen($principal->issuer).':'.$principal->issuer.$principal->subject);
    }

    public function tripCorrelationId(string $tripRequestId): string
    {
        return $this->pseudonym('trip-v1', $tripRequestId);
    }

    public function travelerCorrelationId(string $travelerId): string
    {
        return $this->pseudonym('traveler-v1', $travelerId);
    }

    private function pseudonym(string $label, string $value): string
    {
        $key = $this->key() ?? throw new AuditIdentityUnavailable;

        return hash_hmac('sha256', $label."\0".$value, $key);
    }

    private function key(): ?string
    {
        $key = config('logging.audit_pseudonym_key');
        if (! is_string($key) || strlen($key) < self::MIN_KEY_BYTES) {
            return null;
        }
        if (! app()->environment('local', 'testing') && hash_equals((string) config('app.key'), $key)) {
            return null;
        }

        return $key;
    }
}
