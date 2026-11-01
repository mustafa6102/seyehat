<?php

namespace App\Modules\CandidateGenerator\Infrastructure;

use App\Modules\CandidateGenerator\Contracts\CheckResult;
use App\Modules\CandidateGenerator\Contracts\PruningCheck;
use App\Modules\CandidateGenerator\Contracts\PruningEvidence;
use App\Modules\CandidateGenerator\Contracts\RouteCandidate;
use Illuminate\Contracts\Config\Repository;

final readonly class ConfigPruningEvidence implements PruningEvidence
{
    public const NOT_CONFIGURED = 'CFG-RCG-PRUNING-EVIDENCE';

    public function __construct(private Repository $config) {}

    public function assess(RouteCandidate $candidate, PruningCheck $check): CheckResult
    {
        $all = $this->config->get('self_transfer.pruning_evidence');
        $entry = is_array($all) ? ($all[$candidate->key()][$check->value] ?? null) : null;
        if (! is_array($entry)) {
            return CheckResult::unknown($check, self::NOT_CONFIGURED);
        }

        $verdict = $entry['verdict'] ?? null;
        $reference = $entry['evidence_ref'] ?? null;
        if (! is_string($reference) || trim($reference) === '') {
            return CheckResult::unknown($check, self::NOT_CONFIGURED);
        }

        return match ($verdict) {
            'PASS' => CheckResult::pass($check, trim($reference)),
            'FAIL' => CheckResult::fail($check, trim($reference)),
            default => CheckResult::unknown($check, self::NOT_CONFIGURED),
        };
    }
}
