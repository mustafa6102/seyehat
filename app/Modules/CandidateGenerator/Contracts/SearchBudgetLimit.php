<?php

namespace App\Modules\CandidateGenerator\Contracts;

/** A versioned CFG-SEARCH-BUDGET value (unit: request, scope: provider/operation). */
final readonly class SearchBudgetLimit
{
    public function __construct(
        public int $maxRequests,
        public string $version,
    ) {}
}
