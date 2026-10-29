<?php

namespace App\Modules\Opportunity\Application;

use App\Modules\DecisionSupport\Domain\CurrentEvidence;
use App\Modules\FlightSearch\Domain\Money;
use DateTimeImmutable;

final class SelectedFlightOpportunity
{
    /** The snapshot is loaded through FlightSelectionReader after owner authorization, never accepted from the client. */
    public function evaluate(array $selection, Money $threshold, DateTimeImmutable $now): array
    {
        $verified = CurrentEvidence::instant($selection['verified_at'] ?? null);
        $expiry = CurrentEvidence::instant($selection['expires_at'] ?? null);
        $price = $selection['verified_price'] ?? null;
        $valid = ($selection['status'] ?? null) === 'CURRENT' && $verified !== null && $verified <= $now
            && $expiry !== null && $expiry > $now && $expiry > $verified
            && is_array($price) && is_int($price['minor_units'] ?? null) && $price['minor_units'] >= 0
            && is_string($price['currency'] ?? null) && preg_match('/^[A-Z]{3}$/D', $price['currency']) === 1;
        $status = ! $valid ? 'NOT_VERIFIED' : ($price['currency'] !== $threshold->currency ? 'CURRENCY_MISMATCH'
            : ($price['minor_units'] <= $threshold->minorUnits ? 'MATCH' : 'NO_MATCH'));

        return ['status' => $status, 'threshold' => $threshold->toContract(),
            'observed_price' => $valid ? ['minor_units' => $price['minor_units'], 'currency' => $price['currency']] : null,
            'verified_at' => $verified?->format(DATE_ATOM), 'expires_at' => $expiry?->format(DATE_ATOM),
            'evaluated_at' => $now->format(DATE_ATOM), 'policy_version' => 'selected-flight-threshold-3.22'];
    }
}
