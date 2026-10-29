<?php

namespace App\Modules\DecisionSupport\Domain;

use DateTimeImmutable;

/** v3.22 internal evidence boundary; callers supply canonical, server-sourced facts, never HTTP claims. */
final class CurrentEvidence
{
    public static function instant(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
            return null;
        }
        try {
            $date = new DateTimeImmutable($value);
            $errors = DateTimeImmutable::getLastErrors();

            return $errors !== false && ($errors['warning_count'] || $errors['error_count']) ? null : $date;
        } catch (\Exception) {
            return null;
        }
    }

    public static function value(?array $fact, DateTimeImmutable $now): mixed
    {
        if ($fact === null || ($fact['verification_status'] ?? null) !== 'FRESH'
            || ! in_array($fact['certainty'] ?? null, ['EXACT', 'ESTIMATED'], true)
            || ! is_string($fact['source_reference'] ?? null) || trim($fact['source_reference']) === '') {
            return null;
        }
        $verified = self::instant($fact['verified_at'] ?? null);
        $expires = self::instant($fact['expires_at'] ?? null);
        if ($verified === null || $expires === null || $verified > $now || $expires <= $now || $expires <= $verified) {
            return null;
        }

        return $fact['value'] ?? null;
    }
}
