<?php

namespace App\Modules\Api\Contracts;

use RuntimeException;

final class IncompleteTripRequest extends RuntimeException
{
    /** @param list<string> $missingFields */
    public function __construct(public readonly array $missingFields)
    {
        parent::__construct('Seyahat talebinin zorunlu alanları eksik.');
    }
}
