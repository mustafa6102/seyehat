<?php

namespace App\Modules\FlightSearch\Application;

use InvalidArgumentException;

final class RepriceContinuationPolicy
{
    public function canContinue(string $businessModel, RepriceResult $result, bool $newApprovalGiven = false): bool
    {
        if (! in_array($businessModel, ['MODEL_A', 'MODEL_B', 'MODEL_C'], true)) {
            throw new InvalidArgumentException('Unknown business model.');
        }

        $state = $result->snapshot->offer->state($result->snapshot->capturedAt);
        if ($state === 'EXPIRED') {
            return false;
        }
        if ($state === 'CHANGED') {
            return $newApprovalGiven;
        }
        if ($businessModel === 'MODEL_A') {
            return true;
        }
        if ($state === 'UNVERIFIED') {
            return false;
        }

        return $result->previousApprovalValid;
    }
}
