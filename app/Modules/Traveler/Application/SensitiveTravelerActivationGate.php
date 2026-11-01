<?php

namespace App\Modules\Traveler\Application;

use App\Modules\Authorization\Contracts\AuditIdentity;
use App\Modules\Traveler\Contracts\BasicTravelerActivation;
use App\Modules\Traveler\Contracts\SensitiveTravelerActivation;
use App\Modules\Traveler\Domain\Encryption\KeyEncryptionKeys;
use App\Modules\Traveler\Domain\TravelerIdentityProofs;

/**
 * Every condition is independent and none has a preview exception: Basic Traveler open, Legal, ADR-010, OD-013 and OD-014
 * references recorded, a contract version set in code and ACTIVE, key and identity providers configured and the audit key
 * available.
 */
final readonly class SensitiveTravelerActivationGate implements SensitiveTravelerActivation
{
    private const REQUIRED_REFERENCES = ['legal_approval_ref', 'security_approval_ref', 'retention_decision_ref', 'transfer_decision_ref'];

    public function __construct(
        private BasicTravelerActivation $basic,
        private ActiveContractPointer $contract,
        private KeyEncryptionKeys $keys,
        private TravelerIdentityProofs $proofs,
        private AuditIdentity $auditIdentity,
    ) {}

    public function isActive(): bool
    {
        $version = config('traveler_sensitive.required_contract_version');
        if (config('traveler_sensitive.enabled') !== true || ! is_string($version) || $version === '') {
            return false;
        }
        foreach (self::REQUIRED_REFERENCES as $reference) {
            $value = config('traveler_sensitive.'.$reference);
            if (! is_string($value) || trim($value) === '') {
                return false;
            }
        }

        return $this->basic->isActive() && $this->contract->isAtLeast($version)
            && $this->keys->isConfigured() && $this->proofs->isConfigured() && $this->auditIdentity->isAvailable();
    }
}
