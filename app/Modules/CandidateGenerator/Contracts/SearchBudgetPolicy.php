<?php

namespace App\Modules\CandidateGenerator\Contracts;

interface SearchBudgetPolicy
{
    /** Null while CFG-SEARCH-BUDGET has no approved value for this provider/operation (ADR-006). */
    public function limit(string $providerId, string $operation): ?SearchBudgetLimit;
}
