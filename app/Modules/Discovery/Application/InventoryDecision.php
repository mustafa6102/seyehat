<?php

namespace App\Modules\Discovery\Application;

use App\Modules\DecisionSupport\Domain\CurrentEvidence;
use DateTimeImmutable;
use DateTimeZone;

/** Shared FOOD/EVT projections over server-normalized evidence. Does not fetch or fabricate inventory. */
final class InventoryDecision
{
    public function event(array $event, DateTimeImmutable $now, array $approvedTicketHosts): array
    {
        $status = CurrentEvidence::value($event['status'] ?? null, $now);
        $tickets = CurrentEvidence::value($event['ticket_status'] ?? null, $now);
        $start = CurrentEvidence::instant(CurrentEvidence::value($event['starts_at'] ?? null, $now));
        $end = CurrentEvidence::instant(CurrentEvidence::value($event['ends_at'] ?? null, $now));
        $zone = $event['iana_timezone'] ?? null;
        $zoneValid = is_string($zone) && in_array($zone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true);
        $active = $status === 'SCHEDULED' && $tickets === 'AVAILABLE';
        $fixed = $active && $start !== null && $zoneValid && ($end === null || $end > $start);

        return ['active_recommendation' => $active,
            'ticket_actionable' => $fixed && $this->link($event['ticket_link'] ?? null, $now, $approvedTicketHosts) !== null,
            'fixed_constraint' => $fixed ? ['constraint_type' => 'FIXED', 'starts_at' => $start->format(DATE_ATOM),
                'ends_at' => $end?->format(DATE_ATOM), 'iana_timezone' => $zone] : null,
            'feasibility_status' => 'NOT_EVALUATED', 'calculation_version' => 'inventory-3.22',
            'evidence_refs' => $this->references($event, $now), 'calculated_at' => $now->format(DATE_ATOM)];
    }

    public function food(array $place, DateTimeImmutable $now, array $approvedReservationHosts): array
    {
        $booking = CurrentEvidence::value($place['booking'] ?? null, $now);
        $level = CurrentEvidence::value($place['price_level'] ?? null, $now);
        $price = CurrentEvidence::value($place['price'] ?? null, $now);
        $money = is_array($price) && is_int($price['minor_units'] ?? null) && $price['minor_units'] >= 0
            && is_string($price['currency'] ?? null) && preg_match('/^[A-Z]{3}$/D', $price['currency']) === 1;

        return ['price_kind' => $money ? 'AMOUNT' : (is_string($level) && $level !== '' ? 'PRICE_LEVEL' : 'UNKNOWN'),
            'price' => $money ? ['minor_units' => $price['minor_units'], 'currency' => $price['currency']] : null,
            'price_certainty' => $money ? $place['price']['certainty'] : 'UNKNOWN',
            'price_level' => is_string($level) && $level !== '' ? $level : null,
            'booking_requirement' => in_array($booking, ['REQUIRED', 'RECOMMENDED', 'NOT_REQUIRED'], true) ? $booking : 'UNKNOWN',
            'reservation_actionable' => in_array($booking, ['REQUIRED', 'RECOMMENDED', 'NOT_REQUIRED'], true)
                && $this->link($place['reservation_link'] ?? null, $now, $approvedReservationHosts) !== null,
            'calculation_version' => 'inventory-3.22', 'evidence_refs' => $this->references($place, $now),
            'calculated_at' => $now->format(DATE_ATOM)];
    }

    private function references(array $facts, DateTimeImmutable $now): array
    {
        $refs = [];
        foreach ($facts as $fact) {
            if (is_array($fact) && CurrentEvidence::value($fact, $now) !== null) {
                $refs[] = $fact['source_reference'];
            }
        }

        return array_values(array_unique($refs));
    }

    private function link(?array $fact, DateTimeImmutable $now, array $approvedHosts): ?string
    {
        $url = CurrentEvidence::value($fact, $now);
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['port']) && $parts['port'] !== 443 || ! in_array(strtolower($parts['host'] ?? ''), $approvedHosts, true)) {
            return null;
        }

        return $url;
    }
}
