<?php

namespace App\Modules\Api\Domain\TripRequest;

use App\Modules\ProviderAcl\Contracts\AviationIdentity;

final readonly class LocationRef
{
    public function __construct(
        public string $kind,
        public string $value,
        public string $ianaTimezone,
        public ?AviationIdentity $aviationIdentity = null,
        /** @var array{latitude: float, longitude: float}|null server-assigned coordinates of the selected catalog entry (v3.14) */
        public ?array $coordinates = null,
    ) {}

    /**
     * SCH-LocationRef v3.8: aviation_identity is optional and server-assigned; a present but invalid
     * identity, or one that does not match the kind (airport/IATA_AIRPORT, city/IATA_CITY), rejects the place.
     *
     * @param  array<string, mixed>|null  $value
     */
    public static function fromNullable(?array $value): ?self
    {
        if (! is_string($value['kind'] ?? null) || $value['kind'] === ''
            || ! is_string($value['value'] ?? null) || $value['value'] === ''
            || ! is_string($value['iana_timezone'] ?? null) || $value['iana_timezone'] === '') {
            return null;
        }

        $identity = null;
        if (array_key_exists('aviation_identity', $value)) {
            $identity = AviationIdentity::fromContract($value['aviation_identity']);
            if ($identity === null || ! $identity->matchesKind($value['kind'])) {
                return null;
            }
        }

        // SCH-LocationRef v3.14: optional server-assigned coordinates; a present but invalid pair rejects the place.
        $coordinates = null;
        if (array_key_exists('coordinates', $value)) {
            $latitude = $value['coordinates']['latitude'] ?? null;
            $longitude = $value['coordinates']['longitude'] ?? null;
            if (! is_int($latitude) && ! is_float($latitude) || ! is_int($longitude) && ! is_float($longitude)
                || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
                return null;
            }
            $coordinates = ['latitude' => (float) $latitude, 'longitude' => (float) $longitude];
        }

        return new self($value['kind'], $value['value'], $value['iana_timezone'], $identity, $coordinates);
    }

    /** @return array{kind: string, value: string, iana_timezone: string, aviation_identity?: array{type: string, code: string}} */
    public function toContract(): array
    {
        $place = [
            'kind' => $this->kind,
            'value' => $this->value,
            'iana_timezone' => $this->ianaTimezone,
        ];

        if ($this->aviationIdentity !== null) {
            $place['aviation_identity'] = $this->aviationIdentity->toContract();
        }

        return $this->coordinates === null ? $place : $place + ['coordinates' => $this->coordinates];
    }
}
