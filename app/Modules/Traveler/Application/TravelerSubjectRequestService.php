<?php

namespace App\Modules\Traveler\Application;

use App\Modules\Authorization\Contracts\AccessOutcomeLog;
use App\Modules\Authorization\Contracts\AuditIdentity;
use App\Modules\Authorization\Contracts\PrincipalRef;
use App\Modules\Traveler\Contracts\BasicTravelerRejected;
use App\Modules\Traveler\Contracts\TravelerSubjectRequestRefused;
use App\Modules\Traveler\Contracts\TravelerSubjectRequests;
use App\Modules\Traveler\Contracts\TripTravelerEnrollment;
use App\Modules\Traveler\Domain\TravelerSubjectRequestStore;
use App\Modules\TripBuilder\Contracts\TripDirectory;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;

/** TripTravelerEnrollment::find settles and audits OWNER, Trip membership and the Basic projection before anything is filed. */
final readonly class TravelerSubjectRequestService implements TravelerSubjectRequests
{
    private const OUTSIDE_APPLICATION = 'urn:traveler-subject-request:outside-application';

    private const RECEIVED = 'RECEIVED';

    private const COMPLETED = 'COMPLETED';

    private const REJECTED = 'REJECTED';

    public function __construct(
        private TripTravelerEnrollment $enrollment,
        private TravelerSubjectRequestStore $store,
        private TripDirectory $trips,
        private AuditIdentity $auditIdentity,
        private AccessOutcomeLog $outcomes,
    ) {}

    public function file(PrincipalRef $principal, string $tripRequestId, string $travelerId, string $type): string
    {
        if (! in_array($type, [self::DELETE, self::ACCESS], true)) {
            throw BasicTravelerRejected::invalidRequest();
        }
        $traveler = $this->enrollment->find($principal, $tripRequestId, $travelerId);

        $subjectId = $this->auditIdentity->subjectId($principal);
        $id = (string) Str::uuid();
        $filed = $this->store->open($id, $tripRequestId, $traveler->tripId, $traveler->id, $type, $subjectId, $this->now());
        if ($filed === $id) {
            $this->outcomes->recordSubjectRequest($id, $subjectId, $tripRequestId, $traveler->id, $type, self::RECEIVED);
        }

        return $filed;
    }

    public function fileOnBehalf(string $travelerId, string $type, string $channelRef): string
    {
        if (! $this->auditIdentity->isAvailable()) {
            throw TravelerSubjectRequestRefused::auditUnavailable();
        }
        $tripId = $this->store->tripOfBasicTraveler($travelerId);
        $tripRequestId = $tripId === null ? null : $this->trips->tripRequestIdOf($tripId);
        if ($tripId === null || $tripRequestId === null || ! in_array($type, [self::DELETE, self::ACCESS], true)) {
            throw TravelerSubjectRequestRefused::unknownTraveler();
        }

        $subjectId = $this->auditIdentity->subjectId(new PrincipalRef(self::OUTSIDE_APPLICATION, $channelRef));
        $id = (string) Str::uuid();
        $filed = $this->store->open($id, $tripRequestId, $tripId, $travelerId, $type, $subjectId, $this->now());
        if ($filed === $id) {
            $this->outcomes->recordSubjectRequest($id, $subjectId, $tripRequestId, $travelerId, $type, self::RECEIVED);
        }

        return $filed;
    }

    public function openTypes(PrincipalRef $principal, string $tripRequestId, string $travelerId): array
    {
        return $this->store->openTypes($this->enrollment->find($principal, $tripRequestId, $travelerId)->id);
    }

    public function pending(int $limit): array
    {
        return $this->store->pending($limit);
    }

    public function complete(string $requestId, string $operator, string $decisionRef): void
    {
        $this->close($requestId, self::COMPLETED, true, $operator, $decisionRef);
    }

    public function reject(string $requestId, string $operator, string $decisionRef): void
    {
        $this->close($requestId, self::REJECTED, false, $operator, $decisionRef);
    }

    private function close(string $requestId, string $status, bool $deleteTraveler, string $operator, string $decisionRef): void
    {
        // Checked first: an irreversible removal never happens unaudited.
        if (! $this->auditIdentity->isAvailable()) {
            throw TravelerSubjectRequestRefused::auditUnavailable();
        }

        $request = $this->store->close($requestId, $status, $deleteTraveler, $operator, $decisionRef, $this->now());
        $this->outcomes->recordSubjectRequest($requestId, $request['subject_id'], $request['trip_request_id'], $request['traveler_id'], $request['type'], $status);
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
