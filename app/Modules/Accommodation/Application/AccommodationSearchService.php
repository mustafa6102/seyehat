<?php

namespace App\Modules\Accommodation\Application;

use App\Modules\Accommodation\Infrastructure\Persistence\DatabaseAccommodationSearchJobRepository;
use App\Modules\Accommodation\Infrastructure\Queue\RunAccommodationSearch;
use App\Modules\Api\Contracts\TripRequestView;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;
use Throwable;

/**
 * ACC-001 accommodation search (v3.14): OP-startAccommodationSearch / OP-pollAccommodationSearch and the server-side
 * SCH-AccommodationSearchCriteria derivation of OD-024. Criteria come only from the persisted TripRequest.
 */
final readonly class AccommodationSearchService
{
    /** CFG-ACCOMMODATION-SEARCH-RADIUS (contracts/v3.14/registries/config.yaml): 5 km, provider range 1-100 km. */
    public const SEARCH_RADIUS_KM = 5;

    private const MAX_CHECK_IN_DAYS_AHEAD = 330;

    private const MAX_NIGHTS = 99;

    public function __construct(private DatabaseAccommodationSearchJobRepository $jobs) {}

    /**
     * @return array<string, mixed> the job (existing one for a repeated key)
     *
     * @throws \DomainException 'IDEMPOTENCY_KEY_CONFLICT' when the key belongs to another TripRequest's search
     */
    public function start(string $idempotencyKey, string $tripRequestId): array
    {
        $existing = $this->jobs->findByIdempotencyKey($idempotencyKey);
        if ($existing === null) {
            $searchId = (string) Str::uuid();
            if ($this->jobs->insertIfAbsent($searchId, $idempotencyKey, $tripRequestId)) {
                RunAccommodationSearch::dispatch($searchId)->afterCommit();

                return $this->jobs->find($searchId);
            }
            $existing = $this->jobs->findByIdempotencyKey($idempotencyKey);
        }
        if ($existing['trip_request_id'] !== $tripRequestId) {
            throw new \DomainException('IDEMPOTENCY_KEY_CONFLICT');
        }

        return $existing;
    }

    /** @return array<string, mixed>|null */
    public function poll(string $searchId): ?array
    {
        return $this->jobs->find($searchId);
    }

    /**
     * OD-024 supported subset. Returns the criteria, or a concise Turkish reason when the TripRequest cannot be mapped
     * (GAP-ACC-CRITERIA-001: the job then FAILS with error.code null and no provider call).
     *
     * @return array<string, mixed>|string
     */
    public function criteria(string $tripRequestId, TripRequestView $tripRequest, ?DateTimeImmutable $now = null): array|string
    {
        if ($tripRequest->journeyType !== 'ROUND_TRIP') {
            return 'Konaklama araması yalnız gidiş-dönüş yolculuklarda yapılabilir; tek yönde çıkış tarihi bilinmiyor.';
        }
        if (($tripRequest->outboundDate['type'] ?? null) !== 'EXACT' || ($tripRequest->returnDate['type'] ?? null) !== 'EXACT') {
            return 'Konaklama araması esnek tarihlerle yapılamıyor; kesin gidiş ve dönüş tarihi gerekir.';
        }
        $composition = $tripRequest->passengerComposition;
        if ($composition === null) {
            return 'Konaklama araması için yetişkin, çocuk ve bebek sayıları kayıtlı olmalıdır; yeni bir talep oluşturun.';
        }
        if (($composition['children'] ?? 0) > 0 || ($composition['infants'] ?? 0) > 0) {
            return 'Çocuk veya bebek içeren talepler için konaklama araması henüz yapılamıyor: çocuk yaşları kayıtlı değil.';
        }
        if (($composition['adults'] ?? 0) < 1) {
            return 'Konaklama araması için en az bir yetişkin yolcu gerekir.';
        }
        if ($tripRequest->roomCount === null || $tripRequest->roomCount < 1) {
            return 'Konaklama araması için oda sayısı kayıtlı değil; oda sayısını girerek yeni bir talep oluşturun.';
        }
        $destination = $tripRequest->destinationPlace;
        if (($destination['kind'] ?? null) !== 'city') {
            return 'Konaklama araması için varış noktası bir şehir olarak seçilmiş olmalıdır.';
        }
        $coordinates = $destination['coordinates'] ?? null;
        if (! is_array($coordinates) || ! isset($coordinates['latitude'], $coordinates['longitude'])) {
            return 'Varış şehrinin konumu kayıtlı değil; varış noktasını yeniden seçerek yeni bir talep oluşturun.';
        }

        try {
            // Calendar dates; "today" is taken in the destination's time zone.
            $zone = new DateTimeZone((string) $destination['iana_timezone']);
            $checkIn = new DateTimeImmutable($tripRequest->outboundDate['date'], $zone);
            $checkOut = new DateTimeImmutable($tripRequest->returnDate['date'], $zone);
            $today = ($now ?? new DateTimeImmutable('now'))->setTimezone($zone)->setTime(0, 0);
        } catch (Throwable) {
            return 'Seyahat talebinin tarih veya konum bilgileri konaklama aramasına dönüştürülemedi.';
        }
        if ($checkOut <= $checkIn) {
            return 'Konaklama için çıkış tarihi giriş tarihinden sonra olmalıdır.';
        }
        if ($checkIn < $today) {
            return 'Giriş tarihi geçmişte olduğu için konaklama araması yapılamıyor.';
        }
        if ($today->diff($checkIn)->days > self::MAX_CHECK_IN_DAYS_AHEAD) {
            return 'Konaklama sağlayıcısı en fazla 330 gün sonrasına kadar giriş tarihini destekliyor.';
        }
        if ($checkIn->diff($checkOut)->days > self::MAX_NIGHTS) {
            return 'Konaklama sağlayıcısı en fazla 99 gecelik konaklamayı destekliyor.';
        }

        return [
            'trip_request_id' => $tripRequestId,
            'destination_place' => $destination,
            'destination_coordinates' => ['latitude' => (float) $coordinates['latitude'], 'longitude' => (float) $coordinates['longitude']],
            'search_radius_km' => self::SEARCH_RADIUS_KM,
            'check_in_date' => $tripRequest->outboundDate['date'],
            'check_out_date' => $tripRequest->returnDate['date'],
            'adults' => (int) $composition['adults'],
            'rooms' => $tripRequest->roomCount,
        ];
    }
}
