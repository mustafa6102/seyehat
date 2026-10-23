<?php

namespace App\Modules\CandidateGenerator\Application;

use App\Modules\CandidateGenerator\Contracts\SearchBudgetLimit;

/** FR-RCG-001-01 request counter for one provider/operation within one generation run. */
final class SearchBudgetLedger
{
    private int $used = 0;

    public function __construct(private readonly SearchBudgetLimit $limit) {}

    /** Reserves all requests of a candidate at once so a candidate is never half searched. */
    public function tryReserve(int $requests): bool
    {
        if ($this->used + $requests > $this->limit->maxRequests) {
            return false;
        }

        $this->used += $requests;

        return true;
    }

    public function used(): int
    {
        return $this->used;
    }
}
