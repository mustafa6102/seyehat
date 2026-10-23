<?php

namespace App\Modules\CandidateGenerator\Contracts;

final readonly class CandidateEvaluation
{
    /**
     * @param  list<CheckResult>  $checks
     * @param  list<LegSearchResult>  $legResults  empty unless the candidate was QUERIED
     */
    public function __construct(
        public RouteCandidate $candidate,
        public CandidateOutcome $outcome,
        public array $checks,
        public array $legResults = [],
    ) {}

    public function withOutcome(CandidateOutcome $outcome, array $legResults = []): self
    {
        return new self($this->candidate, $outcome, $this->checks, $legResults);
    }

    public function fullyAnswered(): bool
    {
        foreach ($this->legResults as $result) {
            if (! $result->answered) {
                return false;
            }
        }

        return true;
    }
}
