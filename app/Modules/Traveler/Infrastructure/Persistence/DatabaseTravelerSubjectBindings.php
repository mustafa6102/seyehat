<?php

namespace App\Modules\Traveler\Infrastructure\Persistence;

use App\Modules\Authorization\Contracts\PrincipalRef;
use App\Modules\Traveler\Domain\TravelerSubjectBindings;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Comparison stays in PHP because the columns use the default case-insensitive collation and SCH-PrincipalRef is exact. The
 * unique index is therefore stricter than needed: a case variant of a bound subject is refused, never treated as the same.
 */
final readonly class DatabaseTravelerSubjectBindings implements TravelerSubjectBindings
{
    private const ACTIVE = 'ACTIVE';

    private const REVOKED = 'REVOKED';

    public function __construct(private ConnectionInterface $database) {}

    public function ownTravelerIds(PrincipalRef $principal, string $tripId): array
    {
        return $this->database->table('traveler_subject_bindings')
            ->where('trip_id', $tripId)->where('status', self::ACTIVE)
            ->get(['traveler_id', 'issuer', 'subject'])
            ->filter(fn (object $row): bool => $row->issuer === $principal->issuer && $row->subject === $principal->subject)
            ->map(fn (object $row): string => (string) $row->traveler_id)
            ->values()->all();
    }

    public function bind(string $travelerId, string $tripId, PrincipalRef $principal, string $proofRef, string $verifiedAt): bool
    {
        try {
            $this->database->table('traveler_subject_bindings')->insert([
                'traveler_id' => $travelerId,
                'trip_id' => $tripId,
                'issuer' => $principal->issuer,
                'subject' => $principal->subject,
                'status' => self::ACTIVE,
                'proof_ref' => $proofRef,
                'verified_at' => $verifiedAt,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    public function revoke(string $travelerId, string $tripId, PrincipalRef $principal, string $revokedAt): bool
    {
        if (! in_array($travelerId, $this->ownTravelerIds($principal, $tripId), true)) {
            return false;
        }

        return $this->database->table('traveler_subject_bindings')
            ->where('traveler_id', $travelerId)->where('status', self::ACTIVE)
            ->update(['status' => self::REVOKED, 'revoked_at' => $revokedAt]) === 1;
    }
}
