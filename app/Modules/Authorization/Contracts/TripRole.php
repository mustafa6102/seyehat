<?php

namespace App\Modules\Authorization\Contracts;

/** Roles from contracts/registries/permissions.yaml (RULE-PLT-AUTH-001-01). */
enum TripRole: string
{
    case OWNER = 'owner';
    case COLLABORATOR = 'collaborator';
    case TRAVELER = 'traveler';
    case TRAVELER_DATA_VIEWER = 'traveler_data_viewer';
    case SUPPORT_AGENT = 'support_agent';
    case SYSTEM_WORKER = 'system_worker';
}
