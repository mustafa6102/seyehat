<?php

namespace App\Modules\ProviderAcl\Contracts;

/**
 * SCH-AviationIdentity v3.8: provider-independent IATA location identity. Airport and city codes
 * are distinct identities; a catalog/provider resource id is never an aviation identity.
 */
final readonly class AviationIdentity
{
    public const IATA_AIRPORT = 'IATA_AIRPORT';

    public const IATA_CITY = 'IATA_CITY';

    /** SCH-LocationRef.kind that each identity type belongs to. */
    private const KIND_BY_TYPE = [
        self::IATA_AIRPORT => 'airport',
        self::IATA_CITY => 'city',
    ];

    private function __construct(
        public string $type,
        public string $code,
    ) {}

    public static function of(string $type, string $code): ?self
    {
        return isset(self::KIND_BY_TYPE[$type]) && preg_match('/^[A-Z]{3}$/', $code) === 1 ? new self($type, $code) : null;
    }

    /** Strict parse of the contract shape {type, code}; anything else yields null. */
    public static function fromContract(mixed $value): ?self
    {
        if (! is_array($value) || array_keys($value) !== ['type', 'code'] || ! is_string($value['type']) || ! is_string($value['code'])) {
            return null;
        }

        return self::of($value['type'], $value['code']);
    }

    /** True when this identity may describe a SCH-LocationRef of the given kind. */
    public function matchesKind(string $kind): bool
    {
        return self::KIND_BY_TYPE[$this->type] === $kind;
    }

    /** @return array{type: string, code: string} */
    public function toContract(): array
    {
        return ['type' => $this->type, 'code' => $this->code];
    }
}
