<?php

namespace App\Modules\FlightSearch\Domain;

use DateTimeImmutable;
use LogicException;

final readonly class BaggageAllowance
{
    /** @param list<AncillaryFee> $fees */
    public function __construct(
        public ?bool $personalItem,
        public ?bool $cabinBag,
        public ?bool $checkedBag,
        public array $fees = [],
        public ?string $sourceReference = null,
        public ?DateTimeImmutable $fetchedAt = null,
        public ?DateTimeImmutable $verifiedAt = null,
        public ?DateTimeImmutable $expiresAt = null,
    ) {}

    /** @return array<string, mixed> */
    public function toContract(): array
    {
        if ($this->sourceReference === null || $this->sourceReference === '' || $this->fetchedAt === null) {
            throw new LogicException('Baggage source and freshness metadata are required for contract serialization.');
        }

        return [
            'personal_item' => $this->allowanceContract($this->personalItem),
            'cabin_baggage' => $this->allowanceContract($this->cabinBag),
            'checked_baggage' => $this->allowanceContract($this->checkedBag),
            'fees' => array_map(
                fn (AncillaryFee $fee): array => $fee->toContract(),
                $this->fees,
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function detailsToContract(): array
    {
        return $this->toContract();
    }

    public function fingerprint(): string
    {
        return json_encode([
            'personal_item' => $this->personalItem,
            'cabin_bag' => $this->cabinBag,
            'checked_bag' => $this->checkedBag,
            'fees' => array_map(
                fn (AncillaryFee $fee): array => [
                    'component' => $fee->component,
                    'mandatory' => $fee->mandatory,
                    'amount' => $fee->amount?->toContract(),
                    'certainty' => $fee->certainty,
                ],
                $this->fees,
            ),
        ], JSON_THROW_ON_ERROR);
    }

    public function withSource(
        string $sourceReference,
        DateTimeImmutable $fetchedAt,
        ?DateTimeImmutable $verifiedAt,
        ?DateTimeImmutable $expiresAt = null,
    ): self {
        return new self(
            personalItem: $this->personalItem,
            cabinBag: $this->cabinBag,
            checkedBag: $this->checkedBag,
            fees: $this->fees,
            sourceReference: $sourceReference,
            fetchedAt: $fetchedAt,
            verifiedAt: $verifiedAt,
            expiresAt: $expiresAt,
        );
    }

    /** @return array<string, mixed> */
    private function allowanceContract(?bool $included): array
    {
        $certainty = $included === null || $this->verifiedAt === null ? 'UNKNOWN' : 'EXACT';

        return [
            'value' => $certainty === 'UNKNOWN' ? null : $included,
            'certainty' => $certainty,
            'source_reference' => $this->sourceReference,
            'fetched_at' => $this->fetchedAt->format(DATE_ATOM),
            'verified_at' => $this->verifiedAt?->format(DATE_ATOM),
            'expires_at' => $this->expiresAt?->format(DATE_ATOM),
            'verification_status' => $this->verificationStatus(),
        ];
    }

    private function verificationStatus(): string
    {
        if ($this->verifiedAt === null) {
            return 'UNVERIFIED';
        }

        return $this->expiresAt !== null && $this->expiresAt <= new DateTimeImmutable('now')
            ? 'EXPIRED'
            : 'FRESH';
    }
}
