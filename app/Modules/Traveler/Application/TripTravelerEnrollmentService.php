<?php

namespace App\Modules\Traveler\Application;

use App\Modules\Api\Contracts\OwnershipDecision;
use App\Modules\Api\Contracts\TripRequestOwnership;
use App\Modules\Api\Contracts\TripRequestService;
use App\Modules\Authorization\Contracts\AccessControl;
use App\Modules\Authorization\Contracts\AccessOutcomeLog;
use App\Modules\Authorization\Contracts\AccessRequest;
use App\Modules\Authorization\Contracts\AuditIdentity;
use App\Modules\Authorization\Contracts\PrincipalRef;
use App\Modules\Authorization\Contracts\TripRole;
use App\Modules\Traveler\Contracts\BasicTraveler;
use App\Modules\Traveler\Contracts\BasicTravelerRejected;
use App\Modules\Traveler\Contracts\BasicTravelerRoster;
use App\Modules\Traveler\Contracts\TripTravelerEnrollment;
use App\Modules\Traveler\Domain\Traveler;
use App\Modules\Traveler\Domain\TravelerEnrollmentStore;
use App\Modules\TripBuilder\Contracts\TripDirectory;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;

/**
 * Trip and owner come only from stored records and the id is generated here. OWNER is the only role this slice can
 * establish (D5); a non-owner holds no role, so the matrix denies and audits before the request is read. OD-020 stays
 * OPEN: accepting only ADULT limits this slice and does not define the age_category value set.
 */
final readonly class TripTravelerEnrollmentService implements TripTravelerEnrollment
{
    public function __construct(
        private TripRequestOwnership $ownership,
        private TripRequestService $tripRequests,
        private TripDirectory $trips,
        private AccessControl $access,
        private TravelerEnrollmentStore $store,
        private AuditIdentity $auditIdentity,
        private AccessOutcomeLog $outcomes,
    ) {}

    public function enroll(PrincipalRef $principal, string $tripRequestId, mixed $idempotencyKey, array $profile): BasicTraveler
    {
        $roles = $this->roles($principal, $tripRequestId);
        $operationId = (string) Str::uuid();
        $this->ensureAllowed($principal, $roles, 'Traveler.basic', 'create', $tripRequestId, creatingTraveler: true, operationId: $operationId);

        if ($profile !== ['age_category' => Traveler::UNGUARDED_AGE_CATEGORY]
            || ! is_string($idempotencyKey) || $idempotencyKey === '' || strlen($idempotencyKey) > 255) {
            throw BasicTravelerRejected::invalidRequest();
        }

        $tripId = $this->trips->tripIdOf($tripRequestId) ?? throw BasicTravelerRejected::tripNotInitialized();
        $id = (string) Str::uuid();
        $traveler = Traveler::fromContract(['id' => $id, 'trip_id' => $tripId, 'nationalities' => [], 'travel_documents' => [], 'held_visas' => [], 'residence_permits' => []] + $profile);
        $fingerprint = hash('sha256', json_encode(['createTripTraveler', $tripId, $profile], JSON_THROW_ON_ERROR));

        // Stored hashed: the column collation is case- and trailing-space-insensitive, so two distinct raw keys could collide.
        $enrolled = $this->store->enroll(
            $traveler,
            hash('sha256', $idempotencyKey),
            $fingerprint,
            $this->adultCapacity($tripRequestId),
            (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
        );
        $this->outcomes->recordTravelerCreate(
            $operationId,
            $this->auditIdentity->subjectId($principal),
            $tripRequestId,
            $enrolled->id,
            $enrolled->id === $id ? AccessOutcomeLog::CREATED : AccessOutcomeLog::REPLAYED,
        );

        return $enrolled;
    }

    public function roster(PrincipalRef $principal, string $tripRequestId): BasicTravelerRoster
    {
        $roles = $this->roles($principal, $tripRequestId);
        $tripId = $this->trips->tripIdOf($tripRequestId);
        $this->ensureAllowed($principal, $roles, 'Trip', 'read', $tripRequestId);

        return new BasicTravelerRoster(
            $tripId !== null,
            $this->adultCapacity($tripRequestId),
            $tripId === null ? [] : $this->store->listForTrip($tripId),
            $tripId !== null && $this->store->hasUnverifiedRows($tripId),
        );
    }

    public function find(PrincipalRef $principal, string $tripRequestId, string $travelerId): BasicTraveler
    {
        $roles = $this->roles($principal, $tripRequestId);
        $this->ensureAllowed($principal, $roles, 'Trip', 'read', $tripRequestId);

        $tripId = $this->trips->tripIdOf($tripRequestId);
        $traveler =$tripId === null ? null : $this->store->findInTrip($tripId, $travelerId);
        if ($traveler === null) {
            throw BasicTravelerRejected::notFound('Yolcu bulunamadı.');
        }
        $this->ensureAllowed($principal, $roles, 'Traveler.basic', 'read', $tripRequestId, $traveler->id, [$traveler->id]);

        return $traveler;
    }

    /** @return list<TripRole> */
    private function roles(PrincipalRef $principal, string $tripRequestId): array
    {
        return match ($this->ownership->check($principal, $tripRequestId)) {
            OwnershipDecision::NOT_FOUND => throw BasicTravelerRejected::notFound(),
            OwnershipDecision::ALLOWED => [TripRole::OWNER],
            OwnershipDecision::DENIED => [],
        };
    }

    /** Null without passenger_composition: a legacy traveler_count is never turned into an adult count. */
    private function adultCapacity(string $tripRequestId): ?int
    {
        $adults = $this->tripRequests->get($tripRequestId)?->passengerComposition['adults'] ?? null;

        return is_int($adults) ? $adults : null;
    }

    /**
     * @param  list<TripRole>  $roles
     * @param  list<string>  $tripTravelerIds
     */
    private function ensureAllowed(PrincipalRef $principal, array $roles, string $resource, string $action, string $tripRequestId, ?string $travelerId = null, array $tripTravelerIds = [], bool $creatingTraveler = false, ?string $operationId = null): void
    {
        // The scope is always the TripRequest id: it exists before the Trip, so every record of one Trip correlates.
        $this->access->ensure(new AccessRequest(
            subjectId: $this->auditIdentity->subjectId($principal),
            rolesInTrip: $roles,
            resource: $resource,
            action: $action,
            tripId: $tripRequestId,
            travelerId: $travelerId,
            tripTravelerIds: $tripTravelerIds,
            creatingTraveler: $creatingTraveler,
            operationId: $operationId,
        ));
    }
}
