<?php

namespace App\Modules\FlightSearch\Application;

use RuntimeException;

/** The Idempotency-Key was already used for a search of another TripRequest (v3.4 OP-startFlightSearch). */
final class IdempotencyKeyConflict extends RuntimeException {}
