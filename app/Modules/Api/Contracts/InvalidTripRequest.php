<?php

namespace App\Modules\Api\Contracts;

use RuntimeException;

/** Present journey/date fields violate TRIP-001 v3.9 (RULE-TRIP-001-04/05); nothing is stored. */
final class InvalidTripRequest extends RuntimeException
{
    /** @param list<string> $invalidFields */
    public function __construct(public readonly array $invalidFields)
    {
        parent::__construct('Seyahat talebinin yolculuk tipi veya tarih alanları geçerli değil.');
    }
}
