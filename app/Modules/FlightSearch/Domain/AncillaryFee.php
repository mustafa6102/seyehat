<?php

namespace App\Modules\FlightSearch\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AncillaryFee
{
    public function __construct(
        public string $component,
        public bool $mandatory,
        public ?Money $amount,
        public string $certainty,
        public string $sourceReference,
        public DateTimeImmutable $fetchedAt,
        public ?DateTimeImmutable $verifiedAt,
        public ?DateTimeImmutable $expiresAt = null,
    ) {
        if (! in_array($certainty, ['EXACT', 'ESTIMATED', 'UNKNOWN'], true)) {
            throw new InvalidArgumentException('Invalid ancillary fee certainty.');
        }
        if ($certainty === 'UNKNOWN' && $amount !== null) {
            throw new InvalidArgumentException('Unknown ancillary fees cannot carry a numeric amount.');
        }
        if ($certainty !== 'UNKNOWN' && $amount === null) {
            throw new InvalidArgumentException('Known ancillary fees require a Money amount.');
        }
    }

    public function isVerified(?DateTimeImmutable $asOf = null): bool
    {
        return $this->amount !== null
            && $this->certainty !== 'UNKNOWN'
            && $this->sourceReference !== ''
            && $this->verifiedAt !== null
            && $this->verificationStatus($asOf) === 'FRESH';
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
    public function toContract(): array
    {
        return [
            'component' => $this->component,
            'mandatory' => $this->mandatory,
            'value' => $this->amount?->toContract(),
            'certainty' => $this->certainty,
            'source_reference' => $this->sourceReference,
            'fetched_at' => $this->fetchedAt->format(DATE_ATOM),
            'verified_at' => $this->verifiedAt?->format(DATE_ATOM),
            'expires_at' => $this->expiresAt?->format(DATE_ATOM),
            'verification_status' => $this->verificationStatus(),
        ];
    }
}
