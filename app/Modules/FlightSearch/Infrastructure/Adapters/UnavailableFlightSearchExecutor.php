<?php

namespace App\Modules\FlightSearch\Infrastructure\Adapters;

use App\Modules\FlightSearch\Contracts\FlightSearchCriteria;
use App\Modules\FlightSearch\Contracts\FlightSearchExecutor;
use App\Modules\FlightSearch\Contracts\FlightSearchOutcome;
use RuntimeException;

final class UnavailableFlightSearchExecutor implements FlightSearchExecutor
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function execute(FlightSearchCriteria $criteria, callable $publishPartial): FlightSearchOutcome
    {
        throw new RuntimeException('Üretim uçuş sağlayıcısı yapılandırılmadı.');
    }
}
