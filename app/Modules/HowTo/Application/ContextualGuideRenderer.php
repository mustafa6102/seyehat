<?php

namespace App\Modules\HowTo\Application;

use DateTimeImmutable;
use DateTimeZone;

/** HOW-001 deterministic Turkish renderer. Critical facts are injected only through typed evidence slots. */
final class ContextualGuideRenderer
{
    private const WEEKDAYS = ['Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi', 'Pazar'];

    /** @param array<string, mixed> $poi */
    public function render(string $tripRequestId, int $version, string $itemId, array $poi): array
    {
        $blocks = [[
            'block_id' => 'general-place',
            'kind' => 'GENERAL',
            'text_tr' => $this->generalText($poi),
            'critical' => false,
            'evidence_slots' => [],
        ]];

        foreach ([
            $this->slot('TIME', $poi['opening_hours'] ?? null, $this->hours(...)),
            $this->slot('FEE', $poi['admission'] ?? null, $this->admission(...)),
            $this->unknownSlot('TERMINAL'),
            $this->unknownSlot('PLATFORM'),
            $this->bookingLink($poi['booking'] ?? null),
            $this->slot('ENTRY_RULE', $poi['booking'] ?? null, $this->booking(...)),
        ] as $slot) {
            $blocks[] = [
                'block_id' => 'operational-'.strtolower(str_replace('_', '-', $slot['slot'])),
                'kind' => 'OPERATIONAL',
                'text_tr' => $this->statusText($slot),
                'critical' => true,
                'evidence_slots' => [$slot],
            ];
        }

        return [
            'trip_request_id' => $tripRequestId,
            'plan_revision_version' => $version,
            'item_id' => $itemId,
            'locale' => 'tr-TR',
            'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
            'blocks' => $blocks,
            'has_stale_critical_evidence' => count(array_filter($blocks, fn (array $block): bool => ($block['evidence_slots'][0]['verification_status'] ?? null) === 'STALE')) > 0,
        ];
    }

    /** @param array<string, mixed> $poi */
    private function generalText(array $poi): string
    {
        $name = trim((string) ($poi['name'] ?? ''));
        $address = is_string($poi['location']['address'] ?? null) ? trim($poi['location']['address']) : '';

        return $address === '' ? "{$name} için doğrulanabilir operasyonel bilgileri aşağıda kontrol edin." : "{$name} adresi: {$address}. Operasyonel bilgileri aşağıda kontrol edin.";
    }

    /** @param callable(mixed): ?string $formatter */
    private function slot(string $name, mixed $fact, callable $formatter): array
    {
        $status = $this->verificationStatus($fact);
        $rawValue = is_array($fact) ? ($fact['value'] ?? null) : null;
        $known = $rawValue !== null && $rawValue !== 'UNKNOWN';
        $visible = $known && in_array($status, ['FRESH', 'STALE'], true);

        return [
            'slot' => $name,
            'knowledge_status' => $known ? 'KNOWN' : 'UNKNOWN',
            'display_value' => $visible ? $formatter($rawValue) : null,
            'source_reference' => $this->safeUrl(is_array($fact) ? ($fact['source_reference'] ?? null) : null),
            'verification_status' => $status,
            'verified_at' => is_array($fact) && is_string($fact['verified_at'] ?? null) ? $fact['verified_at'] : null,
            'expires_at' => is_array($fact) && is_string($fact['expires_at'] ?? null) ? $fact['expires_at'] : null,
            'active_cta' => false,
        ];
    }

    private function unknownSlot(string $name): array
    {
        return [
            'slot' => $name,
            'knowledge_status' => 'UNKNOWN',
            'display_value' => null,
            'source_reference' => null,
            'verification_status' => 'UNVERIFIED',
            'verified_at' => null,
            'expires_at' => null,
            'active_cta' => false,
        ];
    }

    private function bookingLink(mixed $fact): array
    {
        $slot = $this->slot('LINK', $fact, fn (mixed $value): ?string => in_array($value, ['REQUIRED', 'RECOMMENDED'], true)
            ? $this->safeUrl(is_array($fact) ? ($fact['source_reference'] ?? null) : null) : null);
        $slot['active_cta'] = $slot['verification_status'] === 'FRESH' && $slot['display_value'] !== null;

        return $slot;
    }

    /** @param array<string, mixed> $slot */
    private function statusText(array $slot): string
    {
        if ($slot['knowledge_status'] === 'UNKNOWN') {
            return $this->label($slot['slot']).': Bilgi mevcut değil.';
        }

        return match ($slot['verification_status']) {
            'FRESH' => $this->label($slot['slot']).': '.($slot['display_value'] ?? 'Bilgi mevcut değil.'),
            'STALE' => $this->label($slot['slot']).': '.($slot['display_value'] ?? 'Bilgi mevcut değil.').' Güncelliğini yitirmiş; yeniden doğrulayın.',
            'EXPIRED' => $this->label($slot['slot']).': Süresi doldu; kullanmayın.',
            default => $this->label($slot['slot']).': Doğrulanamadı; kesin bilgi olarak kullanmayın.',
        };
    }

    private function label(string $slot): string
    {
        return match ($slot) {
            'TIME' => 'Saat', 'FEE' => 'Ücret', 'TERMINAL' => 'Terminal', 'PLATFORM' => 'Platform',
            'LINK' => 'Rezervasyon bağlantısı', 'ENTRY_RULE' => 'Giriş veya rezervasyon kuralı', default => $slot,
        };
    }

    private function verificationStatus(mixed $fact): string
    {
        $status = is_array($fact) ? ($fact['verification_status'] ?? null) : null;

        return in_array($status, ['FRESH', 'STALE', 'EXPIRED', 'UNVERIFIED'], true) ? $status : 'UNVERIFIED';
    }

    private function safeUrl(mixed $value): ?string
    {
        return is_string($value) && str_starts_with($value, 'https://') && filter_var($value, FILTER_VALIDATE_URL) !== false ? $value : null;
    }

    private function hours(mixed $value): ?string
    {
        $weekly = is_array($value) && is_array($value['weekly'] ?? null) ? $value['weekly'] : [];
        if ($weekly === []) {
            return null;
        }

        return implode('; ', array_map(fn (array $slot): string => (self::WEEKDAYS[((int) ($slot['iso_weekday'] ?? 0)) - 1] ?? '?').
            ' '.($slot['open'] ?? '?').'–'.($slot['close'] ?? '?'), $weekly));
    }

    private function admission(mixed $value): ?string
    {
        if (! is_array($value)) {
            return null;
        }

        return match ($value['fee_required'] ?? null) {
            true => 'Giriş ücretli; doğrulanmış tutar ayrıca gösterilmedikçe tutar bilinmiyor.',
            false => 'Giriş ücretsiz.',
            default => null,
        };
    }

    private function booking(mixed $value): ?string
    {
        return match ($value) {
            'REQUIRED' => 'Rezervasyon gerekli.',
            'RECOMMENDED' => 'Rezervasyon önerilir.',
            'NOT_REQUIRED' => 'Rezervasyon gerekmiyor.',
            default => null,
        };
    }
}
