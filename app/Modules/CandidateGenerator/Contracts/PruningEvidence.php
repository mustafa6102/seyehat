<?php

namespace App\Modules\CandidateGenerator\Contracts;

/**
 * Connectivity, detour, savings and compliance evidence for a candidate. An implementation returns
 * UNKNOWN whenever it has no evidence; it never infers a verdict.
 */
interface PruningEvidence
{
    public function assess(RouteCandidate $candidate, PruningCheck $check): CheckResult;
}
