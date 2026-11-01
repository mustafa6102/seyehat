<?php

namespace App\Modules\Traveler\Application;

use App\Modules\Api\Contracts\OwnershipDecision;
use App\Modules\Api\Contracts\TripRequestOwnership;
use App\Modules\Authorization\Contracts\AccessControl;
use App\Modules\Authorization\Contracts\AccessRequest;
use App\Modules\Authorization\Contracts\AuditIdentity;
use App\Modules\Authorization\Contracts\PrincipalRef;
use App\Modules\Authorization\Contracts\TripRole;
use App\Modules\Traveler\Contracts\BasicTravelerRejected;
use App\Modules\Traveler\Contracts\SensitiveTravelerActivation;
use App\Modules\Traveler\Contracts\SensitiveTravelerProfile;
use App\Modules\Traveler\Contracts\SensitiveTravelerProfiles;
use App\Modules\Traveler\Contracts\TravelerDataUnavailable;
use App\Modules\Traveler\Contracts\TravelerStorageClosed;
use App\Modules\Traveler\Domain\Encryption\SensitiveTravelerVault;
use App\Modules\Traveler\Domain\SensitiveDataAccessLog;
use App\Modules\Traveler\Domain\TravelerEnrollmentStore;
use App\Modules\Traveler\Domain\TravelerSubjectBindings;
use App\Modules\TripBuilder\Contracts\TripDirectory;
use Illuminate\Support\Str;

/**
 * Roles come from stored records: OWNER from the TripRequest, TRAVELER only from a verified binding. POLICY-PII-VIEW-001 then
 * needs the Traveler among the principal's own bindings, so OWNER alone never opens another adult's data. The store's
 * projection holds only ADULT rows it created, so a CHILD/INFANT or legacy row never reaches a key.
 */
final readonly class SensitiveTravelerProfileService implements SensitiveTravelerProfiles
{
    private const ALL_RESOURCES = ['Traveler.sensitive_pii', 'TravelerDocument'];

    public function __construct(
        private SensitiveTravelerActivation $activation,
        private TripRequestOwnership $ownership,
        private TripDirectory $trips,
        private TravelerEnrollmentStore $store,
        private AccessControl $access,
        private AuditIdentity $auditIdentity,
        private TravelerSubjectBindings $bindings,
        private SensitiveTravelerVault $vault,
        private SensitiveDataAccessLog $accessLog,
    ) {}

    public function put(PrincipalRef $principal, string $tripRequestId, string $travelerId, array $input): void
    {
        if (! $this->activation->isActive()) {
            throw new TravelerStorageClosed;
        }

        $operationId = (string) Str::uuid();
        $id = $this->authorize($principal, $tripRequestId, $travelerId, 'update', self::ALL_RESOURCES, $operationId);
        $this->vault->store($id, SensitiveTravelerProfile::fromInput($input));
        $this->accessLog->recordFields('update', $principal, $tripRequestId, $id, SensitiveTravelerProfile::FIELDS, $operationId);
    }

    public function addEntries(PrincipalRef $principal, string $tripRequestId, string $travelerId, string $field, array $entries): void
    {
        if (! $this->activation->isActive()) {
            throw new TravelerStorageClosed;
        }

        $operationId = (string) Str::uuid();
        $resource = $field === 'travel_documents' ? 'TravelerDocument' : 'Traveler.sensitive_pii';
        $id = $this->authorize($principal, $tripRequestId, $travelerId, 'update', [$resource], $operationId);
        $this->vault->store($id, SensitiveTravelerProfile::forField($field, $entries));
        $this->accessLog->recordFields('update', $principal, $tripRequestId, $id, [$field], $operationId);
    }

    public function read(PrincipalRef $principal, string $tripRequestId, string $travelerId): ?SensitiveTravelerProfile
    {
        if (! $this->activation->isActive()) {
            throw new TravelerDataUnavailable;
        }

        $operationId = (string) Str::uuid();
        $id = $this->authorize($principal, $tripRequestId, $travelerId, 'read', self::ALL_RESOURCES, $operationId);
        $profile = $this->vault->load($id);
        if ($profile !== null) {
            $this->accessLog->recordFields('read', $principal, $tripRequestId, $id, SensitiveTravelerProfile::FIELDS, $operationId);
        }

        return $profile;
    }

    /** @param list<string> $resources */
    private function authorize(PrincipalRef $principal, string $tripRequestId, string $travelerId, string $action, array $resources, string $operationId): string
    {
        $ownership = $this->ownership->check($principal, $tripRequestId);
        if ($ownership === OwnershipDecision::NOT_FOUND) {
            throw BasicTravelerRejected::notFound();
        }
        $tripId = $this->trips->tripIdOf($tripRequestId) ?? throw BasicTravelerRejected::notFound('Yolcu bulunamadı.');
        $ownTravelerIds = $this->bindings->ownTravelerIds($principal, $tripId);
        $roles = [
            ...($ownership === OwnershipDecision::ALLOWED ? [TripRole::OWNER] : []),
            ...($ownTravelerIds === [] ? [] : [TripRole::TRAVELER]),
        ];

        // Before the lookup, with no caller-supplied id: a non-member learns nothing and writes no chosen value to the audit.
        $this->access->ensure($this->request($principal, $roles, 'Trip', 'read', $tripRequestId, $operationId));

        $traveler = $this->store->findInTrip($tripId, $travelerId) ?? throw BasicTravelerRejected::notFound('Yolcu bulunamadı.');
        foreach ($resources as $resource) {
            $this->access->ensure($this->request($principal, $roles, $resource, $action, $tripRequestId, $operationId, $traveler->id, $ownTravelerIds));
        }

        return $traveler->id;
    }

    /**
     * @param  list<TripRole>  $roles
     * @param  list<string>  $ownTravelerIds
     */
    private function request(PrincipalRef $principal, array $roles, string $resource, string $action, string $tripRequestId, string $operationId, ?string $travelerId = null, array $ownTravelerIds = []): AccessRequest
    {
        return new AccessRequest(
            subjectId: $this->auditIdentity->subjectId($principal),
            rolesInTrip: $roles,
            resource: $resource,
            action: $action,
            tripId: $tripRequestId,
            travelerId: $travelerId,
            tripTravelerIds: $travelerId === null ? [] : [$travelerId],
            ownTravelerIds: $ownTravelerIds,
            operationId: $operationId,
        );
    }
}
