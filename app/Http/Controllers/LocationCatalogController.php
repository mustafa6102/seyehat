<?php

namespace App\Http\Controllers;

use App\Http\Support\AviationLocationSelection;
use App\Modules\ProviderAcl\Contracts\AviationLocationCatalog;
use App\Modules\ProviderAcl\Contracts\CountryOption;
use App\Modules\ProviderAcl\Contracts\LocationCatalog;
use App\Modules\ProviderAcl\Contracts\LocationOption;
use App\Modules\ProviderAcl\Contracts\ProviderFailure;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Web-only lookup endpoints backing the TripPlanner location comboboxes (TRIP-001 UI).
 * General discovery uses LocationCatalog (GeoNames); `baglam=ucus` uses AviationLocationCatalog (OD-022 v3.8).
 */
final class LocationCatalogController extends Controller
{
    private const FLIGHT_CONTEXT = 'ucus';

    public function __construct(
        private readonly LocationCatalog $catalog,
        private readonly AviationLocationCatalog $aviationCatalog,
        private readonly AviationLocationSelection $selection,
    ) {}

    public function countries(): JsonResponse
    {
        // The country list is static reference data and needs no provider credential; a configured catalog may still supply it.
        if (! $this->catalog->isAvailable()) {
            return response()->json(['countries' => $this->staticCountries()]);
        }

        return $this->respond(fn (): array => [
            'countries' => array_map(fn (CountryOption $country): array => $country->toArray(), $this->catalog->countries()),
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        if ($request->query->has('baglam')) {
            return $this->searchFlightLocations($request);
        }

        $countryCode = $request->query('ulke');
        $query = $request->query('q', '');
        if (! is_string($countryCode) || preg_match('/^[A-Za-z]{2}$/', $countryCode) !== 1
            || ! is_string($query) || preg_match('/\p{C}/u', $query) !== 0
            || mb_strlen(trim($query)) > (int) config('services.geonames.max_query_length')) {
            return $this->error('SCHEMA_VALIDATION_FAILED', 'İstek biçimi veya alanları geçerli değil.', 422);
        }
        $countryCode = strtoupper($countryCode);

        return $this->respond(fn (): array => [
            'locations' => array_map(fn (LocationOption $location): array => $location->toArray(), $this->catalog->search($countryCode, $query)),
        ]);
    }

    /** Flight-capable airports/cities; each entry carries its aviation identity and a server-issued selection token. */
    private function searchFlightLocations(Request $request): JsonResponse
    {
        $query = $request->query('q', '');
        $countryCode = $request->query('ulke');
        if ($request->query('baglam') !== self::FLIGHT_CONTEXT
            || ($request->query->has('ulke') && (! is_string($countryCode) || preg_match('/^[A-Za-z]{2}$/', $countryCode) !== 1))
            || ! is_string($query) || preg_match('/\p{C}/u', $query) !== 0
            || mb_strlen(trim($query)) > (int) config('services.duffel.max_query_length')) {
            return $this->error('SCHEMA_VALIDATION_FAILED', 'İstek biçimi veya alanları geçerli değil.', 422);
        }
        if (! $this->aviationCatalog->isAvailable()) {
            return $this->error(null, 'Havalimanı ve şehir araması henüz kullanıma açılmadı.', 503);
        }

        try {
            // The optional country narrows the provider's suggestions by their own canonical country code.
            $locations = $this->aviationCatalog->search($query, is_string($countryCode) ? strtoupper($countryCode) : null);
        } catch (ProviderFailure $failure) {
            return $this->error($failure->kind->reasonCode(), $failure->kind->messageTr().' Havalimanı ve şehir araması şu anda yapılamıyor.', 503);
        }

        return response()->json(['locations' => array_map(fn (LocationOption $location): array => $this->flightEntry($location), $locations)]);
    }

    /** @return list<array{code: string, label: string}> ISO 3166-1 countries with Turkish names, in Turkish alphabetical order. */
    private function staticCountries(): array
    {
        $countries = [];
        foreach ((array) config('countries') as $code => $label) {
            $countries[] = (new CountryOption((string) $code, (string) $label))->toArray();
        }
        $key = fn (string $label): string => strtolower(strtr($label, [
            'Ç' => 'c~', 'ç' => 'c~', 'Ğ' => 'g~', 'ğ' => 'g~', 'I' => 'h~', 'ı' => 'h~', 'İ' => 'i', 'Ö' => 'o~', 'ö' => 'o~',
            'Ş' => 's~', 'ş' => 's~', 'Ü' => 'u~', 'ü' => 'u~', 'Å' => 'a', 'é' => 'e', 'ã' => 'a', 'í' => 'i',
        ]));
        usort($countries, fn (array $a, array $b): int => strcmp($key($a['label']), $key($b['label'])));

        return $countries;
    }

    public function resolve(Request $request): JsonResponse
    {
        if ($request->query->has('secim')) {
            // Restores a flight selection from its own server-issued token; no provider call.
            $location = $this->selection->open($request->query('secim'));

            return $location === null
                ? $this->error(null, 'Seçilen konum doğrulanamadı; listeden yeniden seçin.', 404)
                : response()->json(['location' => $this->flightEntry($location)]);
        }

        $locationId = $request->query('kimlik');
        if (! is_string($locationId)) {
            return $this->error('SCHEMA_VALIDATION_FAILED', 'İstek biçimi veya alanları geçerli değil.', 422);
        }

        $location = null;
        $response = $this->respond(function () use ($locationId, &$location): array {
            $location = $this->catalog->resolve($locationId);

            return ['location' => $location?->toArray()];
        });

        return $response->isOk() && $location === null
            ? $this->error(null, 'Konum bulunamadı.', 404)
            : $response;
    }

    /**
     * Only normalized canonical fields; the catalog/provider resource id is not sent to the browser.
     *
     * @return array<string, mixed>
     */
    private function flightEntry(LocationOption $location): array
    {
        return array_diff_key($location->toArray(), ['id' => true]) + ['selection' => $this->selection->issue($location)];
    }

    /** @param Closure(): array<string, mixed> $load */
    private function respond(Closure $load): JsonResponse
    {
        if (! $this->catalog->isAvailable()) {
            return $this->error(null, 'Konum araması henüz kullanıma açılmadı.', 503);
        }

        try {
            return response()->json($load());
        } catch (ProviderFailure $failure) {
            return $this->error($failure->kind->reasonCode(), $failure->kind->messageTr().' Konum araması şu anda yapılamıyor.', 503);
        }
    }

    private function error(?string $code, string $messageTr, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message_tr' => $messageTr, 'correlation_id' => null, 'details' => null]], $status);
    }
}
