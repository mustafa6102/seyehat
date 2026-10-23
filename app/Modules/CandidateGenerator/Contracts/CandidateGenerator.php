<?php

namespace App\Modules\CandidateGenerator\Contracts;

interface CandidateGenerator
{
    /** Builds, prunes and (within budget) searches the RCG-001 candidate set for one provider. */
    public function generate(string $originId, string $destinationId, string $providerId): CandidateGenerationResult;
}
