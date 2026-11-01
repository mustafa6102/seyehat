<?php

namespace App\Modules\CandidateGenerator\Contracts;

/** Optional request-cost contract; legacy CandidateLegSearch implementations continue to cost one request per leg. */
interface BudgetedCandidateLegSearch extends CandidateLegSearch
{
    public function requestCount(CandidateLeg $leg): int;
}
