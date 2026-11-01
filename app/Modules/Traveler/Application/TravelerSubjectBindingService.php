<?php

namespace App\Modules\Traveler\Application;

use App\Modules\Authorization\Contracts\PrincipalRef;
use App\Modules\Traveler\Contracts\SensitiveTravelerActivation;
use App\Modules\Traveler\Contracts\TravelerBindingRejected;
use App\Modules\Traveler\Contracts\TravelerStorageClosed;
use App\Modules\Traveler\Contracts\TravelerSubjectBinder;
use App\Modules\Traveler\Domain\SensitiveDataAccessLog;
use App\Modules\Traveler\Domain\TravelerEnrollmentStore;
use App\Modules\Traveler\Domain\TravelerIdentityProofs;
use App\Modules\Traveler\Domain\TravelerSubjectBindings;
use App\Modules\TripBuilder\Contracts\TripDirectory;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;
use Throwable;

/** A Trip role plays no part here: only the proof connects a person to a Traveler. The proof reference is never logged. */
final readonly class TravelerSubjectBindingService implements TravelerSubjectBinder
{
    private const FIELDS = ['subject_binding'];

    private const MAX_PROOF_REF_LENGTH = 255;

    public function __construct(
        private SensitiveTravelerActivation $activation,
        private TripDirectory $trips,
        private TravelerEnrollmentStore $store,
        private TravelerIdentityProofs $proofs,
        private TravelerSubjectBindings $bindings,
        private SensitiveDataAccessLog $accessLog,
    ) {}

    public function bind(PrincipalRef $claimant, string $tripRequestId, string $travelerId, #[\SensitiveParameter] string $proofRef): void
    {
        if (! $this->activation->isActive()) {
            throw new TravelerStorageClosed;
        }

        $tripId = $this->trips->tripIdOf($tripRequestId) ?? throw new TravelerBindingRejected;
        if ($this->store->findInTrip($tripId, $travelerId) === null) {
            throw new TravelerBindingRejected;
        }

        $operationId = (string) Str::uuid();
        if (! $this->proofAccepted($claimant, $travelerId, $tripId, $proofRef)
            || ! $this->bindings->bind($travelerId, $tripId, $claimant, $proofRef, $this->now())) {
            $this->accessLog->recordFields('bind_denied', $claimant, $tripRequestId, $travelerId, self::FIELDS, $operationId);

            throw new TravelerBindingRejected;
        }
        $this->accessLog->recordFields('bind', $claimant, $tripRequestId, $travelerId, self::FIELDS, $operationId);
    }

    public function revoke(PrincipalRef $principal, string $tripRequestId, string $travelerId): void
    {
        if (! $this->activation->isActive()) {
            throw new TravelerStorageClosed;
        }

        $tripId = $this->trips->tripIdOf($tripRequestId) ?? throw new TravelerBindingRejected;
        if (! $this->bindings->revoke($travelerId, $tripId, $principal, $this->now())) {
            throw new TravelerBindingRejected;
        }
        $this->accessLog->recordFields('unbind', $principal, $tripRequestId, $travelerId, self::FIELDS, (string) Str::uuid());
    }

    private function proofAccepted(PrincipalRef $claimant, string $travelerId, string $tripId, #[\SensitiveParameter] string $proofRef): bool
    {
        if ($proofRef === '' || strlen($proofRef) > self::MAX_PROOF_REF_LENGTH) {
            return false;
        }

        try {
            return $this->proofs->verifies($claimant, $travelerId, $tripId, $proofRef);
        } catch (Throwable) {
            return false;
        }
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
