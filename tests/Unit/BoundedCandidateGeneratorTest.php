<?php

namespace Tests\Unit;

use App\Modules\CandidateGenerator\Application\BoundedCandidateGenerator;
use App\Modules\CandidateGenerator\Application\SearchBudgetLedger;
use App\Modules\CandidateGenerator\Contracts\CandidateGenerationResult;
use App\Modules\CandidateGenerator\Contracts\CandidateKind;
use App\Modules\CandidateGenerator\Contracts\CandidateLeg;
use App\Modules\CandidateGenerator\Contracts\CandidateLegSearch;
use App\Modules\CandidateGenerator\Contracts\CandidateOutcome;
use App\Modules\CandidateGenerator\Contracts\CandidateSeedSource;
use App\Modules\CandidateGenerator\Contracts\CheckResult;
use App\Modules\CandidateGenerator\Contracts\CheckVerdict;
use App\Modules\CandidateGenerator\Contracts\LegSearchResult;
use App\Modules\CandidateGenerator\Contracts\PruningCheck;
use App\Modules\CandidateGenerator\Contracts\PruningEvidence;
use App\Modules\CandidateGenerator\Contracts\RouteCandidate;
use App\Modules\CandidateGenerator\Contracts\SearchBudgetLimit;
use App\Modules\CandidateGenerator\Contracts\SearchBudgetPolicy;
use App\Modules\CandidateGenerator\Infrastructure\ConfigSearchBudgetPolicy;
use App\Modules\ProviderAcl\Contracts\ProviderCapabilityMatrix;
use App\Modules\ProviderAcl\Contracts\ProviderCapabilityView;
use DateTimeImmutable;
use Illuminate\Config\Repository;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** RCG-001 (TS-RCG-001-01..04 and negative cases). Budget numbers below are test fixtures, not CFG-SEARCH-BUDGET values. */
final class BoundedCandidateGeneratorTest extends TestCase
{
    private const PROVIDER = 'test-provider';

    private RecordingLegSearch $legSearch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->legSearch = new RecordingLegSearch;
    }

    /** TS-RCG-001-01 Cold start seed -> AC-RCG-001-01. */
    #[Test]
    public function cold_start_seeds_produce_a_bounded_candidate_set_within_budget(): void
    {
        $result = $this->generate(hubs: ['HUB1', 'HUB2', 'HUB1', 'ORG', 'DST', ''], alternatives: ['ALT1'], budget: 10);

        self::assertSame(['DIRECT', 'ONE_HUB:HUB1', 'ONE_HUB:HUB2', 'ALTERNATIVE_ARRIVAL:ALT1'], $this->keys($result));
        self::assertSame(['QUERIED', 'QUERIED', 'QUERIED', 'QUERIED'], $this->outcomes($result));
        // 1 direct + 2 legs per hub + 1 alternative arrival leg: exactly six provider requests, all counted.
        self::assertSame(['ORG>DST', 'ORG>HUB1', 'HUB1>DST', 'ORG>HUB2', 'HUB2>DST', 'ORG>ALT1'], $this->legSearch->calls);
        self::assertSame(6, $result->requestCount);
        self::assertLessThanOrEqual($result->budget->maxRequests, $result->requestCount);
        self::assertSame('test-budget-1', $result->budget->version);
        self::assertSame(CandidateGenerationResult::COMPLETE, $result->completeness());
        self::assertSame([], $result->reasonCodes());
        self::assertNull($result->partialMessageTr());
    }

    /** TS-RCG-001-02 Budget dolması -> AC-RCG-001-02; TS-RCG-001-04 Kısmi sonuç etiketi -> AC-RCG-001-04. */
    #[Test]
    public function an_exhausted_budget_stops_provider_work_and_labels_the_result_partial(): void
    {
        $result = $this->generate(hubs: ['HUB1', 'HUB2'], alternatives: ['ALT1'], budget: 3);

        // Direct (1) + HUB1 (2) fill the budget; HUB2 does not fit and the 1-leg alternative is not started either.
        self::assertSame(['QUERIED', 'QUERIED', 'BUDGET_EXHAUSTED', 'BUDGET_EXHAUSTED'], $this->outcomes($result));
        self::assertSame(['ORG>DST', 'ORG>HUB1', 'HUB1>DST'], $this->legSearch->calls);
        self::assertSame(3, $result->requestCount);
        self::assertSame(CandidateGenerationResult::PARTIAL, $result->completeness());
        self::assertSame(['SEARCH_BUDGET_EXHAUSTED'], $result->reasonCodes());
        self::assertSame('Arama bütçesi dolduğu için sonuçlar kısmi olabilir.', $result->partialMessageTr());
        self::assertSame([], $result->withOutcome(CandidateOutcome::BUDGET_EXHAUSTED)[0]->legResults);
    }

    #[Test]
    public function a_candidate_is_never_half_searched_when_only_part_of_it_fits(): void
    {
        $result = $this->generate(hubs: ['HUB1'], alternatives: ['ALT1'], budget: 2);

        // One request is left after the direct search; the two-leg hub is refused whole and nothing follows.
        self::assertSame(['QUERIED', 'BUDGET_EXHAUSTED', 'BUDGET_EXHAUSTED'], $this->outcomes($result));
        self::assertSame(['ORG>DST'], $this->legSearch->calls);
        self::assertSame(1, $result->requestCount);
    }

    /** TS-RCG-001-03 Uygunsuz hub pruning -> AC-RCG-001-03 (RULE-RCG-001-04). */
    #[Test]
    public function a_hub_failing_evidence_is_pruned_and_never_sent_to_the_provider(): void
    {
        $evidence = new MapPruningEvidence(['ONE_HUB:BAD' => [PruningCheck::COMPLIANCE->value => CheckResult::fail(PruningCheck::COMPLIANCE, 'evidence:compliance-1')]]);
        $result = $this->generate(hubs: ['BAD', 'HUB2'], alternatives: [], budget: 10, evidence: $evidence);

        self::assertSame(['QUERIED', 'PRUNED', 'QUERIED'], $this->outcomes($result));
        self::assertSame(['ORG>DST', 'ORG>HUB2', 'HUB2>DST'], $this->legSearch->calls);
        self::assertSame(3, $result->requestCount);
        // Pruning settles the candidate: the set is complete and no budget reason is raised.
        self::assertSame(CandidateGenerationResult::COMPLETE, $result->completeness());
        self::assertSame([], $result->reasonCodes());
    }

    #[Test]
    public function unknown_or_missing_pruning_evidence_is_never_treated_as_pass(): void
    {
        $evidence = new MapPruningEvidence([
            'ONE_HUB:HUB1' => [PruningCheck::DETOUR->value => CheckResult::unknown(PruningCheck::DETOUR, 'GAP-TEST-DETOUR')],
            // A PASS without an evidence reference, and a verdict that answers a different check, are not evidence.
            'ONE_HUB:HUB2' => [PruningCheck::SAVINGS->value => new CheckResult(PruningCheck::SAVINGS, CheckVerdict::PASS)],
            'ONE_HUB:HUB3' => [PruningCheck::CONNECTIVITY->value => CheckResult::pass(PruningCheck::DETOUR, 'evidence:other')],
        ]);
        $result = $this->generate(hubs: ['HUB1', 'HUB2', 'HUB3'], alternatives: [], budget: 10, evidence: $evidence);

        self::assertSame(['QUERIED', 'UNRESOLVED', 'UNRESOLVED', 'UNRESOLVED'], $this->outcomes($result));
        self::assertSame(['ORG>DST'], $this->legSearch->calls);
        self::assertSame(1, $result->requestCount);
        self::assertSame(CandidateGenerationResult::PARTIAL, $result->completeness());
        self::assertSame(['GAP-TEST-DETOUR', BoundedCandidateGenerator::GAP_EVIDENCE_REF], $result->blockers);
        // UNKNOWN is not a budget problem: no registered reason is borrowed for it.
        self::assertSame([], $result->reasonCodes());
        self::assertNull($result->partialMessageTr());
    }

    #[Test]
    public function an_evidence_source_without_any_evidence_leaves_hubs_and_alternatives_unqueried(): void
    {
        $result = $this->generate(hubs: ['HUB1'], alternatives: ['ALT1'], budget: 10, evidence: new NoPruningEvidence);

        self::assertSame(['QUERIED', 'UNRESOLVED', 'UNRESOLVED'], $this->outcomes($result));
        self::assertSame(['ORG>DST'], $this->legSearch->calls);
        self::assertSame(['GAP-TEST-NO-EVIDENCE'], $result->blockers);
    }

    #[Test]
    public function unavailable_provider_capability_makes_zero_provider_calls(): void
    {
        foreach ([
            new ProviderCapabilityView('search', false, 'UNKNOWN', null, null),
            new ProviderCapabilityView('search', true, 'STALE', 'old evidence', new DateTimeImmutable('2026-01-01T00:00:00+00:00')),
            new ProviderCapabilityView('search', true, 'FRESH', null, null),
        ] as $capability) {
            $result = $this->generate(hubs: ['HUB1'], alternatives: ['ALT1'], budget: 10, capability: $capability);

            self::assertSame(['UNRESOLVED', 'UNRESOLVED', 'UNRESOLVED'], $this->outcomes($result));
            self::assertSame(['OD-008'], $result->blockers);
            self::assertSame(0, $result->requestCount);
        }
        self::assertSame([], $this->legSearch->calls);
    }

    #[Test]
    public function verified_unsupported_capability_prunes_every_candidate(): void
    {
        $capability = new ProviderCapabilityView('search', false, 'FRESH', 'evidence:no-search', new DateTimeImmutable('2026-09-29T10:00:00+00:00'));
        $result = $this->generate(hubs: ['HUB1'], alternatives: [], budget: 10, capability: $capability);

        self::assertSame(['PRUNED', 'PRUNED'], $this->outcomes($result));
        self::assertSame([], $this->legSearch->calls);
    }

    #[Test]
    public function without_a_configured_budget_no_provider_call_is_made(): void
    {
        $result = $this->generate(hubs: ['HUB1'], alternatives: ['ALT1'], budget: null);

        self::assertSame(['BUDGET_UNCONFIGURED', 'BUDGET_UNCONFIGURED', 'BUDGET_UNCONFIGURED'], $this->outcomes($result));
        self::assertSame([], $this->legSearch->calls);
        self::assertSame(0, $result->requestCount);
        self::assertNull($result->budget);
        self::assertSame(['CFG-SEARCH-BUDGET', 'ADR-006'], $result->blockers);
        self::assertSame(CandidateGenerationResult::PARTIAL, $result->completeness());
        // Not configured is not "exhausted": the budget reason code is not raised.
        self::assertSame([], $result->reasonCodes());
    }

    #[Test]
    public function the_shipped_budget_config_defines_no_value_and_incomplete_entries_fail_closed(): void
    {
        $shipped = require dirname(__DIR__, 2).'/config/search_budget.php';
        self::assertSame(['providers' => []], $shipped);
        self::assertNull((new ConfigSearchBudgetPolicy(new Repository(['search_budget' => $shipped])))->limit('duffel_flights', 'search'));

        $policy = new ConfigSearchBudgetPolicy(new Repository(['search_budget' => ['providers' => [
            'complete' => ['search' => ['version' => 'v-test', 'max_requests' => 4]],
            'no-version' => ['search' => ['max_requests' => 4]],
            'zero' => ['search' => ['version' => 'v-test', 'max_requests' => 0]],
            'numeric-string' => ['search' => ['version' => 'v-test', 'max_requests' => '4']],
        ]]]));

        self::assertEquals(new SearchBudgetLimit(4, 'v-test'), $policy->limit('complete', 'search'));
        foreach ([['complete', 'reprice'], ['no-version', 'search'], ['zero', 'search'], ['numeric-string', 'search'], ['absent', 'search']] as [$provider, $operation]) {
            self::assertNull($policy->limit($provider, $operation));
        }
    }

    #[Test]
    public function no_seed_source_means_no_fabricated_hub_or_alternative_candidates(): void
    {
        $result = $this->generate(hubs: null, alternatives: null, budget: 10);

        self::assertSame(['DIRECT'], $this->keys($result));
        self::assertSame(['ORG>DST'], $this->legSearch->calls);
        self::assertSame([BoundedCandidateGenerator::GAP_HUB_SEED, BoundedCandidateGenerator::GAP_ALTERNATIVE_ARRIVAL], $result->blockers);
        self::assertSame(CandidateGenerationResult::PARTIAL, $result->completeness());
    }

    #[Test]
    public function more_than_one_hub_cannot_be_represented_or_generated(): void
    {
        $result = $this->generate(hubs: ['HUB1', 'HUB2', 'HUB3'], alternatives: ['ALT1'], budget: 20);
        foreach ($result->evaluations as $evaluation) {
            self::assertLessThanOrEqual(2, count($evaluation->candidate->legs()));
            self::assertFalse($evaluation->candidate->hubId !== null && $evaluation->candidate->arrivalId !== null);
        }
        self::assertSame([CandidateKind::DIRECT, CandidateKind::ONE_HUB, CandidateKind::ALTERNATIVE_ARRIVAL], CandidateKind::cases());

        // A second intermediate point on any shape is rejected at construction.
        foreach ([
            [CandidateKind::ONE_HUB, 'HUB1', 'HUB2'],
            [CandidateKind::ALTERNATIVE_ARRIVAL, 'HUB1', 'ALT1'],
            [CandidateKind::DIRECT, 'HUB1', null],
            [CandidateKind::ONE_HUB, null, null],
            [CandidateKind::ONE_HUB, 'ORG', null],
        ] as [$kind, $hubId, $arrivalId]) {
            try {
                new RouteCandidate($kind, 'ORG', 'DST', $hubId, $arrivalId);
                self::fail('Expected the candidate shape to be rejected.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function provider_results_pass_through_unchanged_and_unanswered_legs_keep_the_result_partial(): void
    {
        $this->legSearch->unanswered = ['HUB1>DST'];
        $result = $this->generate(hubs: ['HUB1'], alternatives: [], budget: 10);

        [$direct, $hub] = $result->evaluations;
        self::assertEquals([new LegSearchResult(true, 'ref:ORG>DST')], $direct->legResults);
        self::assertEquals([new LegSearchResult(true, 'ref:ORG>HUB1'), new LegSearchResult(false)], $hub->legResults);
        self::assertSame(3, $result->requestCount);
        self::assertSame(CandidateGenerationResult::PARTIAL, $result->completeness());
        self::assertSame([], $result->reasonCodes());
    }

    #[Test]
    public function the_ledger_counts_requests_and_never_exceeds_its_limit(): void
    {
        $ledger = new SearchBudgetLedger(new SearchBudgetLimit(3, 'v-test'));

        self::assertTrue($ledger->tryReserve(2));
        self::assertFalse($ledger->tryReserve(2));
        self::assertSame(2, $ledger->used());
        self::assertTrue($ledger->tryReserve(1));
        self::assertFalse($ledger->tryReserve(1));
        self::assertSame(3, $ledger->used());
    }

    /**
     * @param  list<string>|null  $hubs
     * @param  list<string>|null  $alternatives
     */
    private function generate(?array $hubs, ?array $alternatives, ?int $budget, ?PruningEvidence $evidence = null, ?ProviderCapabilityView $capability = null): CandidateGenerationResult
    {
        $capability ??= new ProviderCapabilityView('search', true, 'FRESH', 'evidence:search', new DateTimeImmutable('2026-09-29T10:00:00+00:00'));

        return (new BoundedCandidateGenerator(
            new StaticSeedSource($hubs, $alternatives),
            $evidence ?? new MapPruningEvidence([]),
            new StaticCapabilityMatrix($capability),
            new StaticBudgetPolicy($budget === null ? null : new SearchBudgetLimit($budget, 'test-budget-1')),
            $this->legSearch,
        ))->generate('ORG', 'DST', self::PROVIDER);
    }

    /** @return list<string> */
    private function keys(CandidateGenerationResult $result): array
    {
        return array_map(static fn ($evaluation): string => $evaluation->candidate->key(), $result->evaluations);
    }

    /** @return list<string> */
    private function outcomes(CandidateGenerationResult $result): array
    {
        return array_map(static fn ($evaluation): string => $evaluation->outcome->value, $result->evaluations);
    }
}

final class RecordingLegSearch implements CandidateLegSearch
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<string> */
    public array $unanswered = [];

    public function search(CandidateLeg $leg, string $providerId): LegSearchResult
    {
        $this->calls[] = $key = $leg->fromId.'>'.$leg->toId;

        return in_array($key, $this->unanswered, true) ? new LegSearchResult(false) : new LegSearchResult(true, 'ref:'.$key);
    }
}

final readonly class StaticSeedSource implements CandidateSeedSource
{
    /**
     * @param  list<string>|null  $hubs
     * @param  list<string>|null  $alternatives
     */
    public function __construct(private ?array $hubs, private ?array $alternatives) {}

    public function hubIds(): ?array
    {
        return $this->hubs;
    }

    public function alternativeArrivalIds(string $destinationId): ?array
    {
        return $this->alternatives;
    }
}

/** Test evidence: PASS with a reference unless a candidate/check override is given. */
final readonly class MapPruningEvidence implements PruningEvidence
{
    /** @param array<string, array<string, CheckResult>> $overrides */
    public function __construct(private array $overrides) {}

    public function assess(RouteCandidate $candidate, PruningCheck $check): CheckResult
    {
        return $this->overrides[$candidate->key()][$check->value] ?? CheckResult::pass($check, 'evidence:test');
    }
}

final class NoPruningEvidence implements PruningEvidence
{
    public function assess(RouteCandidate $candidate, PruningCheck $check): CheckResult
    {
        return CheckResult::unknown($check, 'GAP-TEST-NO-EVIDENCE');
    }
}

final readonly class StaticCapabilityMatrix implements ProviderCapabilityMatrix
{
    public function __construct(private ProviderCapabilityView $view) {}

    public function productionCapability(string $providerId, string $operation): ProviderCapabilityView
    {
        return $this->view;
    }
}

final readonly class StaticBudgetPolicy implements SearchBudgetPolicy
{
    public function __construct(private ?SearchBudgetLimit $limit) {}

    public function limit(string $providerId, string $operation): ?SearchBudgetLimit
    {
        return $this->limit;
    }
}
