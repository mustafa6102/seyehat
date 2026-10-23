<?php

namespace App\Modules\FlightSearch\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ProviderSource
{
    public function __construct(
        public string $providerId,
        public string $providerOfferId,
        public string $providerRole,
        public string $distributionChannel,
        public bool $searchable,
        public bool $bookable,
        public string $searchCapabilityStatus,
        public string $bookCapabilityStatus,
        public DateTimeImmutable $fetchedAt,
        public ?DateTimeImmutable $verifiedAt,
        public ?DateTimeImmutable $expiresAt,
    ) {
        if (! in_array($providerRole, ['AGGREGATOR', 'GDS', 'DIRECT_SUPPLIER', 'AFFILIATE', 'UNKNOWN'], true)) {
            throw new InvalidArgumentException('Invalid ProviderRole.');
        }
        if (! in_array($distributionChannel, ['NDC', 'GDS_EDIFACT', 'DIRECT_API', 'OTHER', 'UNKNOWN'], true)) {
            throw new InvalidArgumentException('Invalid DistributionChannel.');
        }
    }

    public function verificationStatus(?DateTimeImmutable $asOf = null): string
    {
        if ($this->verifiedAt === null) {
            return 'UNVERIFIED';
        }

        $asOf ??= new DateTimeImmutable('now');

        return $this->expiresAt !== null && $this->expiresAt <= $asOf ? 'EXPIRED' : 'FRESH';
    }

    /** @return array<string, mixed> */
    public function toContract(?DateTimeImmutable $asOf = null): array
    {
        return [
            'provider_id' => $this->providerId,
            'provider_offer_id' => $this->providerOfferId,
            'provider_role' => $this->providerRole,
            'distribution_channel' => $this->distributionChannel,
            'searchable' => $this->searchable,
            'bookable' => $this->bookable,
            'search_capability_status' => $this->searchCapabilityStatus,
            'book_capability_status' => $this->bookCapabilityStatus,
            'fetched_at' => $this->fetchedAt->format(DATE_ATOM),
            'verified_at' => $this->verifiedAt?->format(DATE_ATOM),
            'expires_at' => $this->expiresAt?->format(DATE_ATOM),
            'verification_status' => $this->verificationStatus($asOf),
        ];
    }
}
