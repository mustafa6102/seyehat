<?php

namespace App\Modules\Traveler\Contracts;

use App\Modules\Authorization\Contracts\AccessDenied;
use App\Modules\Authorization\Contracts\PrincipalRef;

/**
 * Deletion and information requests on one Basic Traveler. The OWNER files them; a person with server access decides each
 * one. Nothing here runs on a schedule and no audit record is removed: retention stays an OD-013 decision.
 */
interface TravelerSubjectRequests
{
    public const DELETE = 'DELETE';

    public const ACCESS = 'ACCESS';

    /**
     * Filing the same open request again returns the existing one.
     *
     * @throws BasicTravelerRejected
     * @throws AccessDenied
     */
    public function file(PrincipalRef $principal, string $tripRequestId, string $travelerId, string $type): string;

    /**
     * Records a request received outside the application, from a person without an account. $channelRef is the operator's
     * reference to that correspondence and is stored only as a pseudonym.
     *
     * @throws TravelerSubjectRequestRefused
     */
    public function fileOnBehalf(string $travelerId, string $type, string $channelRef): string;

    /**
     * @return list<string> open request types of this Traveler
     *
     * @throws BasicTravelerRejected
     * @throws AccessDenied
     */
    public function openTypes(PrincipalRef $principal, string $tripRequestId, string $travelerId): array;

    /** @return list<array{id: string, type: string, created_at: string}> oldest first */
    public function pending(int $limit): array;

    /**
     * A DELETE removes the Basic Traveler row; an ACCESS only closes the request.
     *
     * @throws TravelerSubjectRequestRefused
     */
    public function complete(string $requestId, string $operator, string $decisionRef): void;

    /** @throws TravelerSubjectRequestRefused */
    public function reject(string $requestId, string $operator, string $decisionRef): void;
}
