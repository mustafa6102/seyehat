<?php

namespace App\Modules\CandidateGenerator\Contracts;

/**
 * RULE-RCG-001-01 MVP candidate space. Multi-hub combinations are out of scope; GROUND is not a
 * kind until a ground provider exists (OD-003 BLOCKED, GND-001 is M1C).
 */
enum CandidateKind: string
{
    case DIRECT = 'DIRECT';
    case ONE_HUB = 'ONE_HUB';
    case ALTERNATIVE_ARRIVAL = 'ALTERNATIVE_ARRIVAL';
}
