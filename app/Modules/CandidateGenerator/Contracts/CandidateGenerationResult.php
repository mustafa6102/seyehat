<?php

namespace App\Modules\CandidateGenerator\Contracts;

final readonly class CandidateGenerationResult
{
    public const COMPLETE = 'COMPLETE';

    public const PARTIAL = 'PARTIAL';

    /** contracts/registries/reason_error.yaml RC-SEARCH-BUDGET-EXHAUSTED */
    public const REASON_SEARCH_BUDGET_EXHAUSTED = 'SEARCH_BUDGET_EXHAUSTED';

    /**
     * @param  list<CandidateEvaluation>  $evaluations
     * @param  list<string>  $blockers  OPEN decision/config ids or gap tokens that left part of the space unevaluated
     */
    public function __construct(
        public array $evaluations,
        public int $requestCount,
        public ?SearchBudgetLimit $budget,
        public array $blockers,
    ) {}

    public function completeness(): string
    {
        if ($this->blockers !== []) {
            return self::PARTIAL;
        }

        foreach ($this->evaluations as $evaluation) {
            $settled = $evaluation->outcome === CandidateOutcome::PRUNED
                || ($evaluation->outcome === CandidateOutcome::QUERIED && $evaluation->fullyAnswered());
            if (! $settled) {
                return self::PARTIAL;
            }
        }

        return self::COMPLETE;
    }

    /** @return list<string> registry machine codes */
    public function reasonCodes(): array
    {
        return $this->budgetExhausted() ? [self::REASON_SEARCH_BUDGET_EXHAUSTED] : [];
    }

    /** Turkish partial-result label (registry default_message_tr); null when no registered reason applies. */
    public function partialMessageTr(): ?string
    {
        return $this->budgetExhausted() ? 'Arama bütçesi dolduğu için sonuçlar kısmi olabilir.' : null;
    }

    /** @return list<CandidateEvaluation> */
    public function withOutcome(CandidateOutcome $outcome): array
    {
        return array_values(array_filter(
            $this->evaluations,
            static fn (CandidateEvaluation $evaluation): bool => $evaluation->outcome === $outcome,
        ));
    }

    private function budgetExhausted(): bool
    {
        return $this->withOutcome(CandidateOutcome::BUDGET_EXHAUSTED) !== [];
    }
}
