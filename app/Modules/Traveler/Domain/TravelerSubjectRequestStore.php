<?php

namespace App\Modules\Traveler\Domain;

use App\Modules\Traveler\Contracts\TravelerSubjectRequestRefused;

interface TravelerSubjectRequestStore
{
    /** @return string the request id: $id when created, the existing one when the same request is already open */
    public function open(string $id, string $tripRequestId, string $tripId, string $travelerId, string $type, string $subjectId, string $createdAt): string;

    /** @return string|null the Trip of a Basic Traveler row; null for an unknown or legacy id */
    public function tripOfBasicTraveler(string $travelerId): ?string;

    /** @return list<string> */
    public function openTypes(string $travelerId): array;

    /** @return list<array{id: string, type: string, created_at: string}> */
    public function pending(int $limit): array;

    /**
     * Closes an open request and, when $deleteTraveler holds for a DELETE, removes the Basic row in the same transaction.
     *
     * @return array{trip_request_id: string, traveler_id: string, type: string, subject_id: string}
     *
     * @throws TravelerSubjectRequestRefused
     */
    public function close(string $requestId, string $status, bool $deleteTraveler, string $operator, string $decisionRef, string $processedAt): array;
}
