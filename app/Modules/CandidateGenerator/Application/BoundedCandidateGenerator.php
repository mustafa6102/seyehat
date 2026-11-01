<?php

namespace App\Modules\CandidateGenerator\Application;

use App\Modules\CandidateGenerator\Contracts\CandidateEvaluation;
use App\Modules\CandidateGenerator\Contracts\CandidateGenerationResult;
use App\Modules\CandidateGenerator\Contracts\BudgetedCandidateLegSearch;
use App\Modules\CandidateGenerator\Contracts\CandidateGenerator;
use App\Modules\CandidateGenerator\Contracts\CandidateKind;
use App\Modules\CandidateGenerator\Contracts\CandidateLegSearch;
use App\Modules\CandidateGenerator\Contracts\CandidateOutcome;
use App\Modules\CandidateGenerator\Contracts\CandidateSeedSource;
use App\Modules\CandidateGenerator\Contracts\CheckResult;
use App\Modules\CandidateGenerator\Contracts\CheckVerdict;
use App\Modules\CandidateGenerator\Contracts\PruningCheck;
use App\Modules\CandidateGenerator\Contracts\PruningEvidence;
use App\Modules\CandidateGenerator\Contracts\RouteCandidate;
use App\Modules\CandidateGenerator\Contracts\SearchBudgetPolicy;
use App\Modules\ProviderAcl\Contracts\ProviderCapabilityMatrix;

/**
 * RCG-001: direct + one flight hub + alternative arrival candidates, pruned on supplied evidence and searched
 * only within an explicitly configured CFG-SEARCH-BUDGET. Nothing is invented: missing seeds produce no
 * candidates, missing evidence stays UNKNOWN and a missing budget means no provider call (fail closed).
 */
final readonly class BoundedCandidateGenerator implements CandidateGenerator
{
    public const OPERATION = 'search';

    /** No registry record defines a hub seed or alternative-arrival source yet. */
    public const GAP_HUB_SEED = 'GAP-RCG-HUB-SEED-SOURCE';

    public const GAP_ALTERNATIVE_ARRIVAL = 'GAP-RCG-ALTERNATIVE-ARRIVAL-SOURCE';

    /** A PASS/FAIL verdict without an evidence reference is not evidence. */
    public const GAP_EVIDENCE_REF = 'GAP-RCG-EVIDENCE-REF';

    /** RULE-RCG-001-02 applies the full set to hub candidates. */
    private const HUB_CHECKS = [
        PruningCheck::SEED, PruningCheck::CONNECTIVITY, PruningCheck::DETOUR,
        PruningCheck::PROVIDER_COVERAGE, PruningCheck::SAVINGS, PruningCheck::COMPLIANCE,
    ];

    /** The onward ground link must be evidenced (CONNECTIVITY); ground routing itself is not done here (OD-003). */
    private const ALTERNATIVE_ARRIVAL_CHECKS = [PruningCheck::SEED, PruningCheck::CONNECTIVITY, PruningCheck::PROVIDER_COVERAGE];

    private const DIRECT_CHECKS = [PruningCheck::PROVIDER_COVERAGE];

    public function __construct(
        private CandidateSeedSource $seeds,
        private PruningEvidence $evidence,
        private ProviderCapabilityMatrix $capabilities,
        private SearchBudgetPolicy $budgets,
        private CandidateLegSearch $legSearch,
    ) {}

    public function generate(string $originId, string $destinationId, string $providerId): CandidateGenerationResult
    {
        $blockers = [];
        $candidates = $this->candidates($originId, $destinationId, $blockers);
        $coverage = $this->coverage($providerId);
        $limit = $this->budgets->limit($providerId, self::OPERATION);
        if ($limit !== null && ($limit->maxRequests < 1 || trim($limit->version) === '')) {
            $limit = null;
        }
        $ledger = $limit === null ? null : new SearchBudgetLedger($limit);
        $exhausted = false;
        $evaluations = [];

        foreach ($candidates as $candidate) {
            $checks = $this->checks($candidate, $coverage);
            $verdicts = array_map(static fn (CheckResult $check): CheckVerdict => $check->verdict, $checks);

            if (in_array(CheckVerdict::FAIL, $verdicts, true)) {
                $evaluations[] = new CandidateEvaluation($candidate, CandidateOutcome::PRUNED, $checks);

                continue;
            }
            if (in_array(CheckVerdict::UNKNOWN, $verdicts, true)) {
                foreach ($checks as $check) {
                    if ($check->verdict === CheckVerdict::UNKNOWN && $check->blocker !== null) {
                        $blockers[] = $check->blocker;
                    }
                }
                $evaluations[] = new CandidateEvaluation($candidate, CandidateOutcome::UNRESOLVED, $checks);

                continue;
            }
            if ($ledger === null) {
                array_push($blockers, 'CFG-SEARCH-BUDGET', 'ADR-006');
                $evaluations[] = new CandidateEvaluation($candidate, CandidateOutcome::BUDGET_UNCONFIGURED, $checks);

                continue;
            }

            $legs = $candidate->legs();
            $requestCounts = array_map(
                fn ($leg): int => $this->legSearch instanceof BudgetedCandidateLegSearch
                    ? $this->legSearch->requestCount($leg)
                    : 1,
                $legs,
            );
            $requiredRequests = array_sum($requestCounts);
            $invalidRequestCount = array_filter($requestCounts, static fn (int $requests): bool => $requests < 0) !== [];
            // A multi-leg candidate is atomic: reserve its complete provider cost before starting any leg.
            if ($invalidRequestCount || ($requiredRequests > 0 && ($exhausted || ! $ledger->tryReserve($requiredRequests)))) {
                $exhausted = true;
                $evaluations[] = new CandidateEvaluation($candidate, CandidateOutcome::BUDGET_EXHAUSTED, $checks);

                continue;
            }

            $legResults = [];
            foreach ($legs as $leg) {
                $legResults[] = $this->legSearch->search($leg, $providerId);
            }
            $evaluations[] = new CandidateEvaluation(
                $candidate,
                CandidateOutcome::QUERIED,
                $checks,
                $legResults,
            );
        }

        return new CandidateGenerationResult($evaluations, $ledger?->used() ?? 0, $limit, array_values(array_unique($blockers)));
    }

    /**
     * @param  list<string>  $blockers
     * @return list<RouteCandidate>
     */
    private function candidates(string $originId, string $destinationId, array &$blockers): array
    {
        $candidates = [new RouteCandidate(CandidateKind::DIRECT, $originId, $destinationId)];

        $hubIds = $this->seeds->hubIds();
        if ($hubIds === null) {
            $blockers[] = self::GAP_HUB_SEED;
        }
        foreach ($this->distinctPoints($hubIds ?? [], $originId, $destinationId) as $hubId) {
            $candidates[] = new RouteCandidate(CandidateKind::ONE_HUB, $originId, $destinationId, hubId: $hubId);
        }

        $arrivalIds = $this->seeds->alternativeArrivalIds($destinationId);
        if ($arrivalIds === null) {
            $blockers[] = self::GAP_ALTERNATIVE_ARRIVAL;
        }
        foreach ($this->distinctPoints($arrivalIds ?? [], $originId, $destinationId) as $arrivalId) {
            $candidates[] = new RouteCandidate(CandidateKind::ALTERNATIVE_ARRIVAL, $originId, $destinationId, arrivalId: $arrivalId);
        }

        return $candidates;
    }

    /**
     * @param  list<mixed>  $ids
     * @return list<string> seed order kept; duplicates, blanks and the route's own endpoints dropped
     */
    private function distinctPoints(array $ids, string $originId, string $destinationId): array
    {
        $points = [];
        foreach ($ids as $id) {
            if (is_string($id) && $id !== '' && $id !== $originId && $id !== $destinationId && ! in_array($id, $points, true)) {
                $points[] = $id;
            }
        }

        return $points;
    }

    /** @return list<CheckResult> */
    private function checks(RouteCandidate $candidate, CheckResult $coverage): array
    {
        $applicable = match ($candidate->kind) {
            CandidateKind::DIRECT => self::DIRECT_CHECKS,
            CandidateKind::ONE_HUB => self::HUB_CHECKS,
            CandidateKind::ALTERNATIVE_ARRIVAL => self::ALTERNATIVE_ARRIVAL_CHECKS,
        };

        return array_map(fn (PruningCheck $check): CheckResult => match ($check) {
            PruningCheck::SEED => CheckResult::pass($check, 'seed:'.($candidate->hubId ?? $candidate->arrivalId)),
            PruningCheck::PROVIDER_COVERAGE => $coverage,
            default => $this->supplied($candidate, $check),
        }, $applicable);
    }

    /** Evidence for another check is never applied; absent references cannot support PASS or FAIL. */
    private function supplied(RouteCandidate $candidate, PruningCheck $check): CheckResult
    {
        $result = $this->evidence->assess($candidate, $check);
        if ($result->check !== $check) {
            return CheckResult::unknown($check, self::GAP_EVIDENCE_REF);
        }
        if ($result->verdict === CheckVerdict::UNKNOWN) {
            return $result;
        }
        if ($result->evidenceRef === null || trim($result->evidenceRef) === '') {
            return CheckResult::unknown($check, self::GAP_EVIDENCE_REF);
        }

        return $result;
    }

    /** Only FRESH production evidence decides coverage; anything else is UNKNOWN (OD-008), never PASS or FAIL. */
    private function coverage(string $providerId): CheckResult
    {
        $capability = $this->capabilities->productionCapability($providerId, self::OPERATION);
        if ($capability->status !== 'FRESH' || $capability->evidence === null || trim($capability->evidence) === '') {
            return CheckResult::unknown(PruningCheck::PROVIDER_COVERAGE, 'OD-008');
        }

        return $capability->supported
            ? CheckResult::pass(PruningCheck::PROVIDER_COVERAGE, $capability->evidence)
            : CheckResult::fail(PruningCheck::PROVIDER_COVERAGE, $capability->evidence);
    }
}
