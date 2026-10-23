<?php

namespace App\Modules\ProviderAcl\Contracts;

/**
 * Canonical catalog entry that fills SCH-LocationRef: id -> value, kind -> kind,
 * ianaTimezone -> iana_timezone, aviationIdentity -> aviation_identity (v3.8, flight catalog only).
 * Coordinates stay server-side for later stories.
 */
final readonly class LocationOption
{
    public function __construct(
        public string $id,
        public string $kind,
        public string $label,
        public string $countryCode,
        public string $ianaTimezone,
        public ?float $latitude,
        public ?float $longitude,
        public ?AviationIdentity $aviationIdentity = null,
    ) {}

    /** @return array{id: string, kind: string, label: string, country_code: string, iana_timezone: string, aviation_identity?: array{type: string, code: string}} */
    public function toArray(): array
    {
        $entry = [
            'id' => $this->id,
            'kind' => $this->kind,
            'label' => $this->label,
            'country_code' => $this->countryCode,
            'iana_timezone' => $this->ianaTimezone,
        ];

        return $this->aviationIdentity === null ? $entry : $entry + ['aviation_identity' => $this->aviationIdentity->toContract()];
    }
}
