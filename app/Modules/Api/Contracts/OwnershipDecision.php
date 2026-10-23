<?php

namespace App\Modules\Api\Contracts;

enum OwnershipDecision: string
{
    case ALLOWED = 'ALLOWED';
    /** Different principal, or a legacy TripRequest without owner (never claimable). */
    case DENIED = 'DENIED';
    case NOT_FOUND = 'NOT_FOUND';
}
