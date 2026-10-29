<?php

namespace App\Modules\CandidateGenerator\Application;

use App\Modules\CandidateGenerator\Contracts\SearchBudgetLimit;

/** FR-RCG-001-01 request counter for one provider/operation within one generation run. */
final class SearchBudgetLedger
{
    private int $used = 0;

    public function __construct(private readonly SearchBudgetLimit $limit) {}

    /** Reserves all requests of a candidate without allowing invalid counts to refund the budget. */
    public function tryReserve(int $requests): bool
    {
        if ($requests < 1 || $this->limit->maxRequests < 1 || trim($this->limit->version) === '') {
            return false;
        }
        if ($requests > $this->limit->maxRequests - $this->used) {
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
