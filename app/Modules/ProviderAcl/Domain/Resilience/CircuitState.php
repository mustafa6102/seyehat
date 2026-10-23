<?php

namespace App\Modules\ProviderAcl\Domain\Resilience;

enum CircuitState: string
{
    case CLOSED = 'CLOSED';
    case OPEN = 'OPEN';
    case HALF_OPEN = 'HALF_OPEN';
}
