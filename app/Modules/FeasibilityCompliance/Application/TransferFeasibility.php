<?php

namespace App\Modules\FeasibilityCompliance\Application;

use App\Modules\DecisionSupport\Domain\CurrentEvidence;
use DateTimeImmutable;
use InvalidArgumentException;

final class TransferFeasibility
{
    private const COMPONENTS = ['deplane', 'immigration', 'reclaim', 'ground', 'check_in_cutoff', 'security', 'buffer'];

    /** Internal seconds-based calculation. Unknown applicability is never treated as a zero duration. */
    public function evaluate(array $input, DateTimeImmutable $now): array
    {
        $arrival = CurrentEvidence::instant(CurrentEvidence::value($input['arrival'] ?? null, $now));
        $departure = CurrentEvidence::instant(CurrentEvidence::value($input['departure'] ?? null, $now));
        $available = $arrival !== null && $departure !== null ? $departure->getTimestamp() - $arrival->getTimestamp() : null;
        if ($available !== null && (int) $departure->format('u') < (int) $arrival->format('u')) {
            --$available;
        }
        $protected = CurrentEvidence::value($input['protected_connection'] ?? null, $now);
        $required = 0;
        $unknown = ! is_bool($protected) || $available === null;
        $estimated = false;
        $included = [];
        $refs = [];
        $versions = [];
        foreach (['arrival', 'departure', 'protected_connection'] as $key) {
            if (CurrentEvidence::value($input[$key] ?? null, $now) !== null) {
                $refs[] = $input[$key]['source_reference'];
                $estimated = $estimated || $input[$key]['certainty'] === 'ESTIMATED';
            }
        }
        $components = $input['components'] ?? [];
        if ($protected === true) {
            $components = ['mct' => ['required' => null, 'duration' => $input['mct'] ?? null]] + $components;
        }
        foreach ($protected === true ? ['mct', ...self::COMPONENTS] : self::COMPONENTS as $name) {
            $component = $components[$name] ?? [];
            $mandatory = $name === 'mct' || ($protected === false && in_array($name, ['deplane', 'buffer'], true));
            $applies = $mandatory ? true : CurrentEvidence::value($component['required'] ?? null, $now);
            if (! is_bool($applies)) {
                $unknown = true;
                continue;
            }
            if (! $mandatory) {
                $refs[] = $component['required']['source_reference'];
                $estimated = $estimated || $component['required']['certainty'] === 'ESTIMATED';
            }
            if (! $applies) {
                continue;
            }
            if ($protected === true && $name !== 'mct') {
                $covered = CurrentEvidence::value($component['included_in_mct'] ?? null, $now);
                if (! is_bool($covered)) {
                    $unknown = true;
                    continue;
                }
                $refs[] = $component['included_in_mct']['source_reference'];
                $estimated = $estimated || $component['included_in_mct']['certainty'] === 'ESTIMATED';
                if ($covered) {
                    continue;
                }
            }
            $duration = CurrentEvidence::value($component['duration'] ?? null, $now);
            if (! is_int($duration) || $duration < 0) {
                $unknown = true;
                continue;
            }
            if ($duration > PHP_INT_MAX - $required) {
                throw new InvalidArgumentException('Transfer duration overflows the supported integer range.');
            }
            if ($name === 'buffer') {
                $version = $component['configuration_version'] ?? null;
                if (! is_string($version) || trim($version) === '') {
                    $unknown = true;
                    continue;
                }
                $versions[] = $version;
            }
            $required += $duration;
            $included[] = $name;
            $refs[] = $component['duration']['source_reference'];
            $estimated = $estimated || $component['duration']['certainty'] === 'ESTIMATED';
        }
        // A separately evidenced ready-at instant checks the absolute deadline without adding the cutoff twice.
        $ready = CurrentEvidence::instant(CurrentEvidence::value($input['check_in_ready_at'] ?? null, $now));
        $cutoff = CurrentEvidence::instant(CurrentEvidence::value($input['check_in_deadline'] ?? null, $now));
        $missed = $ready !== null && $cutoff !== null && $ready > $cutoff;
        if (($input['check_in_deadline'] ?? null) !== null && ($ready === null || $cutoff === null)) {
            $unknown = true;
        }
        foreach (['check_in_ready_at', 'check_in_deadline'] as $key) {
            if (CurrentEvidence::value($input[$key] ?? null, $now) !== null) {
                $refs[] = $input[$key]['source_reference'];
            }
        }
        // Even an incomplete nonnegative sum can prove that the available window is too short.
        $short = $available !== null && ($available < 0 || (is_bool($protected) && $available < $required));
        $status = $missed || $short ? 'INFEASIBLE' : ($unknown ? 'UNKNOWN' : 'FEASIBLE');
        $reasons = $missed ? ['CHECK_IN_CUTOFF_MISSED'] : ($short ? ['TRANSFER_WINDOW_INSUFFICIENT'] : ($unknown ? ['EVIDENCE_INSUFFICIENT'] : []));
        if ($estimated) {
            $reasons[] = 'TRANSFER_DURATION_ESTIMATED';
        }

        return ['status' => $status, 'available_seconds' => $available, 'required_seconds' => $unknown ? null : $required,
            'known_required_seconds' => $required, 'certainty' => $unknown ? 'UNKNOWN' : ($estimated ? 'ESTIMATED' : 'EXACT'),
            'decision_cap' => $status === 'INFEASIBLE' ? 'REJECTED' : (($unknown || $estimated) ? 'SHOWN_WITH_WARNING' : 'NO_ADDITIONAL_RESTRICTION'),
            'included_components' => $included, 'reason_codes' => $reasons, 'evidence_refs' => array_values(array_unique($refs)),
            'configuration_versions' => array_values(array_unique($versions)), 'calculation_version' => 'transfer-seconds-3.22',
            'calculated_at' => $now->format(DATE_ATOM)];
    }
}
