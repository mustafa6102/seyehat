<?php

namespace App\Modules\FlightSearch\Application;

interface FlightSearchDispatcher
{
    public function dispatch(string $searchId): void;
}
