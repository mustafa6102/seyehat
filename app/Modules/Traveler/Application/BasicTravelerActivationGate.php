<?php

namespace App\Modules\Traveler\Application;

use App\Modules\Authorization\Contracts\AuditIdentity;
use App\Modules\Traveler\Contracts\BasicTravelerActivation;

/**
 * Fail-closed activation (D2): the flag alone only opens listed preview environments. Every other environment also needs
 * a Legal approval reference and registry/active_contract.json at the required version; an unreadable pointer stays closed.
 * In every environment the audit pseudonym key must be available, because each operation here is audited.
 */
final readonly class BasicTravelerActivationGate implements BasicTravelerActivation
{
    public function __construct(
        private AuditIdentity $auditIdentity,
        private ActiveContractPointer $contract,
    ) {}

    public function isActive(): bool
    {
        if (config('traveler_basic.enabled') !== true || ! $this->auditIdentity->isAvailable()) {
            return false;
        }
        if (app()->environment(config('traveler_basic.preview_environments', []))) {
            return true;
        }

        $reference = config('traveler_basic.legal_approval_ref');

        return is_string($reference) && trim($reference) !== ''
            && $this->contract->isAtLeast((string) config('traveler_basic.required_contract_version'));
    }
}
