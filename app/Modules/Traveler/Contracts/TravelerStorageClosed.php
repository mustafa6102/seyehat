<?php

namespace App\Modules\Traveler\Contracts;

use RuntimeException;

/** ADR-010 field encryption and the minor rules are not active: the profile is refused unwritten, with no value in the error. */
final class TravelerStorageClosed extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Bu yolcu bilgileri şu anda kaydedilemiyor.');
    }
}
