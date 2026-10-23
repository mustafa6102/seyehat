<?php

namespace App\Modules\FlightSearch\Application;

use App\Modules\FlightSearch\Domain\OfferChangeSet;
use App\Modules\FlightSearch\Domain\OfferSnapshot;
use App\Modules\ProviderAcl\Contracts\ProviderCapabilityView;

final readonly class RepriceResult
{
    public OfferSnapshot $snapshot;

    public string $capabilityStatus;

    /** @param list<string> $reasonCodes */
    public function __construct(
        public OfferSnapshot $previous,
        public OfferSnapshot $current,
        public OfferChangeSet $changes,
        public array $reasonCodes,
        public bool $previousApprovalValid,
        public bool $continuationAllowed,
        public ProviderCapabilityView $repriceCapability,
        public string $messageTr,
    ) {
        $this->snapshot = $current;
        $this->capabilityStatus = $repriceCapability->status;
    }

    /** @return array<string, mixed> */
    public function toContract(): array
    {
        return [
            'previous' => $this->previous->toContract(),
            'current' => $this->current->toContract(),
            'changes' => $this->changes->toContract(),
            'reason_codes' => $this->reasonCodes,
            'previous_approval_valid' => $this->previousApprovalValid,
            'continuation_allowed' => $this->continuationAllowed,
            'reprice_capability' => $this->repriceCapability->toContract(),
        ];
    }
}
