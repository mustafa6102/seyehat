<?php

namespace App\Modules\FlightSearch\Domain;

use RuntimeException;

/**
 * The provider could not confirm the offer (rejected, no longer returned, malformed or otherwise failed) for a
 * reason other than a timeout. The offer becomes UNVERIFIED (TRN-OFFER-03); it is never presented as current.
 */
final class RepriceFailed extends RuntimeException {}
