<?php

namespace App\Http\Support;

use App\Modules\ProviderAcl\Contracts\AviationIdentity;
use App\Modules\ProviderAcl\Contracts\LocationOption;
use DateTimeZone;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\StringEncrypter;

/**
 * Trust boundary for flight locations (OD-022 v3.8): the browser only echoes an opaque, encrypted and
 * MAC-authenticated token that the server issued for a normalized AviationLocationCatalog result.
 * Browser-supplied kind/value/timezone/IATA values are never trusted for aviation identity.
 */
final readonly class AviationLocationSelection
{
    private const PURPOSE = 'aviation-location-selection:v1';

    public function __construct(private StringEncrypter $encrypter) {}

    public function issue(LocationOption $location): string
    {
        if ($location->aviationIdentity === null) {
            throw new \InvalidArgumentException('Only aviation catalog entries can be selected for flights.');
        }

        return $this->encrypter->encryptString((string) json_encode([
            'purpose' => self::PURPOSE,
            'id' => $location->id,
            'kind' => $location->kind,
            'label' => $location->label,
            'country_code' => $location->countryCode,
            'iana_timezone' => $location->ianaTimezone,
            'aviation_identity' => $location->aviationIdentity->toContract(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** The server-issued selection, or null for a forged, tampered or malformed token. */
    public function open(mixed $token): ?LocationOption
    {
        if (! is_string($token) || $token === '' || strlen($token) > 4096) {
            return null;
        }

        try {
            $payload = json_decode($this->encrypter->decryptString($token), true, 4, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null;
        }

        $identity = AviationIdentity::fromContract($payload['aviation_identity'] ?? null);
        if (! is_array($payload) || ($payload['purpose'] ?? null) !== self::PURPOSE || $identity === null
            || ! is_string($payload['kind'] ?? null) || ! $identity->matchesKind($payload['kind'])
            || ! is_string($payload['id'] ?? null) || $payload['id'] === ''
            || ! is_string($payload['label'] ?? null) || $payload['label'] === ''
            || ! is_string($payload['country_code'] ?? null)
            || ! is_string($payload['iana_timezone'] ?? null) || ! in_array($payload['iana_timezone'], DateTimeZone::listIdentifiers(DateTimeZone::ALL), true)) {
            return null;
        }

        return new LocationOption($payload['id'], $payload['kind'], $payload['label'], $payload['country_code'], $payload['iana_timezone'], null, null, $identity);
    }
}
