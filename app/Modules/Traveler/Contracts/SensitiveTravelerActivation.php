<?php

namespace App\Modules\Traveler\Contracts;

interface SensitiveTravelerActivation
{
    public function isActive(): bool;
}
