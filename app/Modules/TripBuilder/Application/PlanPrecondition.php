<?php

namespace App\Modules\TripBuilder\Application;

/**
 * OD-027 concurrency precondition: If-None-Match: * (no Trip yet) or If-Match: "<version>" (CURRENT PlanRevision).
 * Exactly one of them; anything else is not a precondition (400 RC-VALIDATION-SCHEMA).
 */
final readonly class PlanPrecondition
{
    private function __construct(public ?int $ifMatchVersion, public bool $ifNoneMatchAny) {}

    public static function fromHeaders(?string $ifMatch, ?string $ifNoneMatch): ?self
    {
        if ($ifMatch !== null && $ifNoneMatch !== null) {
            return null;
        }
        if ($ifNoneMatch !== null) {
            return trim($ifNoneMatch) === '*' ? new self(null, true) : null;
        }
        if ($ifMatch !== null && preg_match('/^"([1-9][0-9]{0,9})"$/', trim($ifMatch), $match) === 1) {
            return new self((int) $match[1], false);
        }

        return null;
    }

    public static function etag(int $version): string
    {
        return '"'.$version.'"';
    }

    public function fingerprintPart(): string
    {
        return $this->ifNoneMatchAny ? '*' : self::etag((int) $this->ifMatchVersion);
    }
}
