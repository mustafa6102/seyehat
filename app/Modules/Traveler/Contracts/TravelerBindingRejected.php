<?php

namespace App\Modules\Traveler\Contracts;

use RuntimeException;

/** One answer for an unknown Traveler, a refused proof and an existing binding, so a claim reveals nothing about any of them. */
final class TravelerBindingRejected extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Yolcu kimliği doğrulanamadı.');
    }
}
