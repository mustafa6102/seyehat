<?php

namespace App\Modules\CandidateGenerator\Contracts;

enum CandidateOutcome: string
{
    /** Every leg was sent to the provider within budget. */
    case QUERIED = 'QUERIED';
    /** At least one pruning check failed on evidence; never sent to the provider (RULE-RCG-001-04). */
    case PRUNED = 'PRUNED';
    /** No check failed but some evidence is UNKNOWN; not sent to the provider. */
    case UNRESOLVED = 'UNRESOLVED';
    /** Eligible, but the provider/operation budget had no room left (RULE-RCG-001-05). */
    case BUDGET_EXHAUSTED = 'BUDGET_EXHAUSTED';
    /** Eligible, but CFG-SEARCH-BUDGET has no value for the provider/operation (ADR-006 OPEN). */
    case BUDGET_UNCONFIGURED = 'BUDGET_UNCONFIGURED';
}
