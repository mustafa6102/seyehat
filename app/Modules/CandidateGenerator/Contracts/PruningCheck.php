<?php

namespace App\Modules\CandidateGenerator\Contracts;

/** RULE-RCG-001-02 pruning dimensions. */
enum PruningCheck: string
{
    case SEED = 'SEED';
    case CONNECTIVITY = 'CONNECTIVITY';
    case DETOUR = 'DETOUR';
    case PROVIDER_COVERAGE = 'PROVIDER_COVERAGE';
    case SAVINGS = 'SAVINGS';
    case COMPLIANCE = 'COMPLIANCE';
}
