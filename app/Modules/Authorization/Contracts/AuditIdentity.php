<?php

namespace App\Modules\Authorization\Contracts;

/**
 * Stable, keyed pseudonyms for audit records. PrincipalRef parts and trip ids never reach a log line; these values let
 * records of one subject or one Trip be correlated, and cannot be reversed or recomputed without the key.
 */
interface AuditIdentity
{
    /** False when no acceptable key is configured; every path that must be audited stays closed then. */
    public function isAvailable(): bool;

    /** @throws AuditIdentityUnavailable */
    public function subjectId(PrincipalRef $principal): string;

    /**
     * One value per TripRequest, whether or not its Trip exists yet.
     *
     * @throws AuditIdentityUnavailable
     */
    public function tripCorrelationId(string $tripRequestId): string;

    /**
     * One value per Traveler across every audit record; recomputing it from a stored id joins records to that row.
     *
     * @throws AuditIdentityUnavailable
     */
    public function travelerCorrelationId(string $travelerId): string;
}
