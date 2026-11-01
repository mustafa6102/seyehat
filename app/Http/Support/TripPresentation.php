<?php

namespace App\Http\Support;

use App\Modules\Api\Contracts\TravelDate;
use App\Modules\Api\Contracts\TripJourney;
use App\Modules\Api\Contracts\TripRequestView;
use App\Modules\ProviderAcl\Contracts\LocationCatalog;
use App\Modules\ProviderAcl\Contracts\ProviderFailure;
use Illuminate\Support\Carbon;

/** Turkish display of a stored TripRequest's places and journey, shared by the summary and flight search pages. */
final readonly class TripPresentation
{
    /** Location kinds allowed for SCH-LocationRef by TRIP-001, with Turkish display labels. */
    public const LOCATION_KINDS = [
        'city' => 'Şehir',
        'airport' => 'Havaalanı',
        'station' => 'İstasyon',
        'supported_location' => 'Diğer konum',
    ];

    /** Approved TripRequest states rendered for ordinary users; unknown internal values fail closed. */
    public function status(string $status): string
    {
        return match ($status) {
            'SEARCHABLE' => 'Uçuş aramaya hazır',
            'DRAFT' => 'Taslak',
            default => 'Durum bilgisi kullanılamıyor',
        };
    }

    /** Stored plan transition reasons rendered without exposing internal reason codes. */
    public function planReason(string $reason): string
    {
        return $reason === 'RC-DECISION-TRANSITION-APPLIED'
            ? 'Kullanıcı değişikliği'
            : 'Değişiklik gerekçesi ayrıntılı olarak gösterilemiyor';
    }
    public function __construct(private LocationCatalog $locationCatalog) {}

    /**
     * Human label from the canonical catalog; a flight location shows its stored aviation code. Falls back
     * to the stored value when it cannot be resolved.
     *
     * @param  array{kind: string, value: string, iana_timezone: string, aviation_identity?: array{type: string, code: string}}  $place
     * @return array{label: string, kind: string, timezone: string, resolved: bool}
     */
    public function place(array $place): array
    {
        $label = $place['aviation_identity']['code'] ?? null;
        if ($label === null && str_starts_with($place['value'], 'geonames:') && $this->locationCatalog->isAvailable()) {
            try {
                $label = $this->locationCatalog->resolve($place['value'])?->label;
            } catch (ProviderFailure) {
                $label = null;
            }
        }

        $kind = TripPresentation::LOCATION_KINDS[$place['kind']] ?? $place['kind'];
        // A catalog identity (e.g. geonames:<id>) is technical; it is never used as the visible name.
        $catalogIdentity = preg_match('/^[a-z_]+:/', $place['value']) === 1;

        return [
            'label' => $label ?? ($catalogIdentity ? $kind : $place['value']),
            'kind' => $kind,
            'timezone' => $place['iana_timezone'],
            'resolved' => $label !== null,
            'catalog_identity' => $catalogIdentity,
            'value' => $place['value'],
            // Server-assigned aviation code (v3.8), only for flight catalog selections; never derived from a name.
            'code' => $place['aviation_identity']['code'] ?? null,
        ];
    }

    /**
     * Turkish display of the stored journey. A legacy (pre-v3.9) date_window is shown as recorded, labelled as
     * having no outbound/return meaning; it is never presented as a departure date.
     *
     * @return array{type: string|null, outbound: array{mode: string, text: string}|null, return: array{mode: string, text: string}|null, legacy: array{mode: string, text: string}|null}
     */
    public function journey(TripRequestView $tripRequest): array
    {
        $format = fn (mixed $date): string => is_string($date) ? Carbon::parse($date)->locale('tr')->translatedFormat('j F Y, l') : '—';
        $travelDate = function (?array $date) use ($format): ?array {
            $parsed = TravelDate::fromContract($date);

            return match ($parsed?->type) {
                TravelDate::EXACT => ['mode' => 'Kesin tarih', 'text' => $format($parsed->earliest)],
                TravelDate::FLEXIBLE => ['mode' => 'Esnek tarih aralığı (başlangıç ve bitiş dahil)', 'text' => $format($parsed->earliest).' – '.$format($parsed->latest)],
                default => null,
            };
        };
        $legacy = $tripRequest->dateWindow;
        // Nights only between two exact dates of a round trip; flexible ranges never yield a count.
        $outbound = TravelDate::fromContract($tripRequest->outboundDate);
        $return = TravelDate::fromContract($tripRequest->returnDate);
        $nights = $tripRequest->journeyType === TripJourney::ROUND_TRIP && $outbound?->type === TravelDate::EXACT && $return?->type === TravelDate::EXACT
            ? (int) Carbon::parse($outbound->earliest)->diffInDays(Carbon::parse($return->earliest))
            : null;

        // Compact range for the summary bar, only from exact dates ("1 – 10 Oca 2027"); flexible dates keep the long text.
        $short = fn (Carbon $date, string $pattern): string => $date->locale('tr')->translatedFormat($pattern);
        $range = null;
        if ($outbound?->type === TravelDate::EXACT) {
            $from = Carbon::parse($outbound->earliest);
            $to = $return?->type === TravelDate::EXACT ? Carbon::parse($return->earliest) : null;
            $range = match (true) {
                $to === null => $short($from, 'j M Y'),
                $from->isSameMonth($to) => $short($from, 'j').' – '.$short($to, 'j M Y'),
                $from->isSameYear($to) => $short($from, 'j M').' – '.$short($to, 'j M Y'),
                default => $short($from, 'j M Y').' – '.$short($to, 'j M Y'),
            };
        }

        return [
            'nights' => $nights,
            'range' => $range,
            'type' => [TripJourney::ONE_WAY => 'Tek yön', TripJourney::ROUND_TRIP => 'Gidiş-dönüş'][$tripRequest->journeyType ?? ''] ?? null,
            'outbound' => $travelDate($tripRequest->outboundDate),
            'return' => $travelDate($tripRequest->returnDate),
            'legacy' => match (true) {
                ! $tripRequest->isLegacyDateModel() => null,
                isset($legacy['exact_date']) => ['mode' => 'Kesin tarih', 'text' => $format($legacy['exact_date'])],
                default => ['mode' => 'Esnek tarih aralığı', 'text' => $format($legacy['start_date'] ?? null).' – '.$format($legacy['end_date'] ?? null)],
            },
        ];
    }
}
