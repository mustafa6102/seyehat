<?php

namespace App\Modules\FlightSearch\Domain;

final class RepriceReason
{
    public const OFFER_CHANGED = 'RC-EVIDENCE-OFFER-CHANGED';

    public const OFFER_EXPIRED = 'RC-EVIDENCE-OFFER-EXPIRED';

    public const PROVIDER_TIMEOUT = 'RC-PROVIDER-TIMEOUT';

    public const REPRICE_UNVERIFIED = 'RC-EVIDENCE-REPRICE-UNVERIFIED';

    private function __construct() {}
}
