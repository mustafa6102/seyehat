<?php

namespace App\Modules\Traveler\Contracts;

use RuntimeException;

/** Encrypted data cannot be returned: closed, missing key, or failed authentication. Never carries a value or the cause. */
final class TravelerDataUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Bu yolcu bilgileri şu anda görüntülenemiyor.');
    }
}
