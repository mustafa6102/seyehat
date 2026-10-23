<?php

namespace App\Modules\FlightSearch\Infrastructure\Queue;

use App\Modules\FlightSearch\Application\FlightSearchDispatcher;

final class LaravelFlightSearchDispatcher implements FlightSearchDispatcher
{
    public function dispatch(string $searchId): void
    {
        RunFlightSearch::dispatch($searchId)->afterCommit();
    }
}
