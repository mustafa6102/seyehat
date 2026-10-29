<?php

namespace App\Modules\FeasibilityCompliance\Application;

use App\Modules\DecisionSupport\Domain\CurrentEvidence;
use DateTimeImmutable;

final class ConnectionProtection
{
    /** Facts are normalized per connection. A shared booking reference is deliberately not a protection input. */
    public function evaluate(array $facts, DateTimeImmutable $now): array
    {
        $separate = CurrentEvidence::value($facts['separate_ticket'] ?? null, $now);
        $protection = 'UNKNOWN';
        $refs = [];
        $timeline = [];
        $reasons = [];
        $used = [];
        if ($separate === true) {
            $protection = 'UNPROTECTED';
            $reasons[] = 'CONNECTION_UNPROTECTED';
            $used[] = 'separate_ticket';
        } else {
            foreach (['through_ticket', 'interline_protection', 'explicit_protection'] as $key) {
                if (CurrentEvidence::value($facts[$key] ?? null, $now) === true) {
                    $protection = 'PROTECTED';
                    $used[] = $key;
                    break;
                }
            }
        }
        if ($protection === 'UNKNOWN') {
            $reasons[] = 'PROTECTION_UNKNOWN';
        }
        foreach (['reclaim' => ['BAGGAGE_RECLAIM', 'BAGGAGE_RECLAIM_REQUIRED'], 'terminal_change' => ['TERMINAL_TRANSFER', 'TERMINAL_TRANSFER_REQUIRED'], 'airport_change' => ['AIRPORT_TRANSFER', 'AIRPORT_TRANSFER_REQUIRED']] as $key => [$step, $reason]) {
            $required = CurrentEvidence::value($facts[$key] ?? null, $now);
            $timeline[] = ['kind' => $step, 'required' => is_bool($required) ? $required : null];
            if (is_bool($required)) {
                $used[] = $key;
            }
            if ($required === true) {
                $reasons[] = $reason;
                if ($key === 'reclaim') {
                    $timeline[] = ['kind' => 'CHECK_IN', 'required' => true];
                }
            }
        }
        foreach ($used as $key) {
            $refs[] = $facts[$key]['source_reference'];
        }

        return ['protection' => $protection, 'risk_level' => 'UNKNOWN', 'decision_cap' => 'SHOWN_WITH_WARNING',
            'timeline' => $timeline, 'reason_codes' => $reasons, 'evidence_refs' => array_values(array_unique($refs)),
            'calculation_version' => 'connection-protection-3.22', 'calculated_at' => $now->format(DATE_ATOM)];
    }
}
