<?php

namespace App\Modules\ProviderAcl\Contracts;

use DateTimeImmutable;

final readonly class ProviderCapabilityView
{
    public function __construct(
        public string $operation,
        public bool $supported,
        public string $status,
        public ?string $evidence,
        public ?DateTimeImmutable $verifiedAt,
    ) {}

    /**
     * SCH-ProviderCapability v3.3 contract representation.
     *
     * @return array{operation: string, supported: bool, status: string, evidence: ?string, verified_at: ?string, verification_status: string}
     */
    public function toContract(): array
    {
        return [
            'operation' => $this->operation,
            'supported' => $this->supported,
            'status' => $this->status,
            'evidence' => $this->evidence,
            'verified_at' => $this->verifiedAt?->format(DATE_ATOM),
            'verification_status' => $this->verificationStatus(),
        ];
    }

    private function verificationStatus(): string
    {
        return in_array($this->status, ['FRESH', 'STALE', 'EXPIRED'], true)
            ? $this->status
            : 'UNVERIFIED';
    }
}
