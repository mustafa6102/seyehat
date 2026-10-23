<?php

namespace App\Modules\FlightSearch\Application;

use RuntimeException;

/** The criteria locations cannot be represented for a flight provider (OD-022); no provider is called. */
final class UnsupportedFlightSearchLocation extends RuntimeException {}
