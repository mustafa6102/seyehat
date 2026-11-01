<?php

/*
 * TRV-001 Basic Traveler activation (D1, D2). Closed unless explicitly opened: production additionally needs a recorded
 * Legal approval reference and an ACTIVE contract at required_contract_version. See BasicTravelerActivationGate.
 */
return [
    'enabled' => env('TRAVELER_BASIC_ENABLED', false) === true,
    'legal_approval_ref' => env('TRAVELER_BASIC_LEGAL_APPROVAL_REF'),
    'required_contract_version' => '3.24.0',
    'preview_environments' => ['local', 'testing'],
];
