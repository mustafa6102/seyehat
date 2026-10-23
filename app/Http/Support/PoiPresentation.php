<?php

namespace App\Http\Support;

/**
 * Turkish server-side presentation of a canonical SCH-Poi snapshot (v3.15/v3.16), with the same uncertainty rules as the
 * POI drawer (public/assets/poi.js): only FRESH evidence is shown without a caveat, unknown admission is never free or 0,
 * unknown hours never mean open, booking uncertainty stays explicit and no duration is inferred. No categories (OD-026).
 */
final class PoiPresentation
{
    private const WEEKDAYS = ['Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi', 'Pazar'];

    private const ZERO_DECIMAL_CURRENCIES = ['BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'UYI', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    private const THREE_DECIMAL_CURRENCIES = ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'];

    /**
     * @param  array<string, mixed>  $poi
     * @return array{name: string, address: string, unverified: bool, facts: list<string>, hours: list<string>, booking_url: string|null}
     */
    public static function describe(array $poi): array
    {
        $hours = $poi['opening_hours'] ?? null;

        return [
            'name' => (string) ($poi['name'] ?? ''),
            'address' => is_string($poi['location']['address'] ?? null) ? $poi['location']['address'] : 'Adres bilgisi yok',
            'unverified' => ! self::fresh($hours ?? ($poi['admission'] ?? null)),
            'facts' => [
                self::admission($poi['admission'] ?? null),
                self::booking($poi['booking'] ?? null),
                self::duration($poi['visit_duration'] ?? null),
                self::status($poi['business_status'] ?? null),
            ],
            'hours' => self::hours($hours),
            'booking_url' => self::bookingUrl($poi['booking'] ?? null),
        ];
    }

    private static function fresh(mixed $fact): bool
    {
        return is_array($fact) && ($fact['verification_status'] ?? null) === 'FRESH';
    }

    private static function caveat(mixed $fact): string
    {
        return self::fresh($fact) ? '' : ' (doğrulanmamış bilgi)';
    }

    private static function admission(mixed $fact): string
    {
        $value = is_array($fact) && is_array($fact['value'] ?? null) ? $fact['value'] : [];
        $amount = self::money($value['amount'] ?? null);
        if (($value['fee_required'] ?? null) === true) {
            return 'Giriş ücretli'.($amount !== null ? ': '.$amount : ' (tutar bilinmiyor)').self::caveat($fact);
        }
        if (($value['fee_required'] ?? null) === false) {
            return 'Giriş ücretsiz'.self::caveat($fact);
        }

        return $amount !== null ? 'Giriş ücreti: '.$amount.self::caveat($fact) : 'Giriş ücreti bilinmiyor';
    }

    private static function booking(mixed $fact): string
    {
        return match (is_array($fact) ? ($fact['value'] ?? null) : null) {
            'REQUIRED' => 'Rezervasyon gerekli'.self::caveat($fact),
            'RECOMMENDED' => 'Rezervasyon önerilir'.self::caveat($fact),
            'NOT_REQUIRED' => 'Rezervasyon gerekmez'.self::caveat($fact),
            default => 'Rezervasyon gerekip gerekmediği bilinmiyor',
        };
    }

    /** A reservation link only for FRESH evidence from an approved reservation source (SCH-Poi.booking); never OSM/website. */
    private static function bookingUrl(mixed $fact): ?string
    {
        $url = is_array($fact) ? ($fact['source_reference'] ?? null) : null;

        return self::fresh($fact) && in_array($fact['value'] ?? null, ['REQUIRED', 'RECOMMENDED'], true)
            && is_string($url) && str_starts_with($url, 'https://') ? $url : null;
    }

    private static function duration(mixed $duration): string
    {
        $minutes = is_array($duration) ? ($duration['minutes'] ?? null) : null;

        return is_array($duration) && ($duration['certainty'] ?? null) === 'ESTIMATED' && is_int($minutes)
            ? "Tahmini ziyaret süresi: yaklaşık {$minutes} dakika (tahmin)"
            : 'Ziyaret süresi bilinmiyor';
    }

    private static function status(mixed $fact): string
    {
        return match (is_array($fact) ? ($fact['value'] ?? null) : null) {
            'OPERATIONAL' => 'Faaliyette'.self::caveat($fact),
            'CLOSED_TEMPORARILY' => 'Geçici olarak kapalı'.self::caveat($fact),
            'CLOSED_PERMANENTLY' => 'Kalıcı olarak kapalı'.self::caveat($fact),
            default => 'Açık veya kapalı olduğu bilinmiyor',
        };
    }

    /** @return list<string> canonical weekly hours only; no "open now" and no claim about unlisted days */
    private static function hours(mixed $fact): array
    {
        $weekly = is_array($fact) && is_array($fact['value']['weekly'] ?? null) ? $fact['value']['weekly'] : [];
        if ($weekly === []) {
            return ['Çalışma saatleri bilinmiyor; ziyaret öncesinde yerin kendi kaynağından kontrol edin.'];
        }
        $label = implode(', ', array_filter([($fact['certainty'] ?? null) === 'ESTIMATED' ? 'tahmini' : 'kaynaklı', self::fresh($fact) ? null : 'doğrulanmamış bilgi']));
        $lines = ["Çalışma saatleri ({$label}):"];
        foreach ($weekly as $slot) {
            $lines[] = (self::WEEKDAYS[((int) ($slot['iso_weekday'] ?? 0)) - 1] ?? '?').': '.($slot['open'] ?? '?').'–'.($slot['close'] ?? '?');
        }
        $lines[] = 'Listede olmayan günler için bilgi yok.';

        return $lines;
    }

    /** SCH-Money as "18,00 EUR" (minor units by ISO 4217 exponent); null when there is no sourced amount. */
    private static function money(mixed $money): ?string
    {
        if (! is_array($money) || ! is_int($money['minor_units'] ?? null) || ! is_string($money['currency'] ?? null)) {
            return null;
        }
        $currency = $money['currency'];
        $exponent = in_array($currency, self::ZERO_DECIMAL_CURRENCIES, true) ? 0 : (in_array($currency, self::THREE_DECIMAL_CURRENCIES, true) ? 3 : 2);

        return number_format($money['minor_units'] / (10 ** $exponent), $exponent, ',', '.').' '.$currency;
    }
}
