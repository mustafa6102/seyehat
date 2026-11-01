<?php

/*
 * TRV-001 sensitive profile activation. Closed with no preview exception. required_contract_version is null on purpose: no
 * contract defines these operations yet, so opening them takes a code change after that contract is ACTIVE. References record
 * approvals; they do not replace them. See SensitiveTravelerActivationGate.
 */
return [
    'enabled' => env('TRAVELER_SENSITIVE_ENABLED', false) === true,
    'legal_approval_ref' => env('TRAVELER_SENSITIVE_LEGAL_APPROVAL_REF'),
    'security_approval_ref' => env('TRAVELER_SENSITIVE_ADR010_APPROVAL_REF'),
    'retention_decision_ref' => env('TRAVELER_SENSITIVE_OD013_DECISION_REF'),
    'transfer_decision_ref' => env('TRAVELER_SENSITIVE_OD014_DECISION_REF'),
    'required_contract_version' => null,

    // Class names, in code rather than the environment: choosing a provider is an ADR-010 decision. A null or invalid value
    // keeps the Unconfigured implementation, which refuses everything. See AppServiceProvider.
    'key_provider' => null,
    'identity_proof_provider' => null,
];
