<?php

namespace App\Modules\ProviderAcl\Domain;

use App\Modules\ProviderAcl\Contracts\ProviderCapabilityView;
use DateTimeImmutable;

final readonly class ProviderCapability
{
    public function __construct(
        public string $providerId,
        public string $operation,
        public string $environment,
        public bool $supported,
        public string $status,
        public ?string $evidence,
        public ?DateTimeImmutable $verifiedAt,
    ) {}

    public function toProductionView(): ProviderCapabilityView
    {
        $hasEvidence = $this->evidence !== null
            && $this->evidence !== ''
            && $this->verifiedAt !== null;
        $status = $hasEvidence ? $this->status : 'UNVERIFIED';
        $hasVerifiedEvidence = $this->environment === 'production'
            && $hasEvidence
            && in_array($status, ['FRESH', 'STALE'], true);

        return new ProviderCapabilityView(
            operation: $this->operation,
            supported: $this->supported && $hasVerifiedEvidence,
            status: $status,
            evidence: $this->evidence,
            verifiedAt: $this->verifiedAt,
        );
    }
}
