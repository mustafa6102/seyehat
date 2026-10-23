<?php

namespace App\Modules\Authorization\Domain;

/**
 * Mirror of contracts/registries/permissions.yaml (v3.2.2) matrix: resource:action => role => decision.
 * Decisions are ALLOW, DENY or a POLICY-* id evaluated by MatrixAccessControl.
 */
final class PermissionMatrix
{
    public const VERSION = '3.2.2';

    public const DECISIONS = [
        'Trip:read' => ['owner' => 'ALLOW', 'collaborator' => 'POLICY-TRIP-001', 'traveler' => 'POLICY-TRIP-001', 'traveler_data_viewer' => 'DENY', 'support_agent' => 'DENY', 'system_worker' => 'POLICY-SYSTEM-001'],
        'Trip:update' => ['owner' => 'ALLOW', 'collaborator' => 'POLICY-TRIP-001', 'traveler' => 'POLICY-TRIP-001', 'traveler_data_viewer' => 'DENY', 'support_agent' => 'DENY', 'system_worker' => 'POLICY-SYSTEM-001'],
        'TripRequest:create' => ['owner' => 'ALLOW', 'collaborator' => 'POLICY-TRIP-001', 'traveler' => 'POLICY-TRIP-001', 'traveler_data_viewer' => 'DENY', 'support_agent' => 'DENY', 'system_worker' => 'POLICY-SYSTEM-001'],
        'TripRequest:read' => ['owner' => 'ALLOW', 'collaborator' => 'POLICY-TRIP-001', 'traveler' => 'POLICY-TRIP-001', 'traveler_data_viewer' => 'DENY', 'support_agent' => 'DENY', 'system_worker' => 'POLICY-SYSTEM-001'],
        'TripRequest:update' => ['owner' => 'ALLOW', 'collaborator' => 'POLICY-TRIP-001', 'traveler' => 'POLICY-TRIP-001', 'traveler_data_viewer' => 'DENY', 'support_agent' => 'DENY', 'system_worker' => 'POLICY-SYSTEM-001'],
        'TripRequest:search' => ['owner' => 'ALLOW', 'collaborator' => 'POLICY-TRIP-001', 'traveler' => 'POLICY-TRIP-001', 'traveler_data_viewer' => 'DENY', 'support_agent' => 'DENY', 'system_worker' => 'POLICY-SYSTEM-001'],
        'Traveler.basic:create' => ['owner' => 'POLICY-TRAVELER-001', 'collaborator' => 'POLICY-TRAVELER-001', 'traveler' => 'POLICY-TRAVELER-001', 'traveler_data_viewer' => 'POLICY-TRAVELER-001', 'support_agent' => 'DENY', 'system_worker' => 'POLICY-SYSTEM-001'],
        'Traveler.basic:read' => ['owner' => 'POLICY-TRAVELER-001', 'collaborator' => 'POLICY-TRAVELER-001', 'traveler' => 'POLICY-TRAVELER-001', 'traveler_data_viewer' => 'POLICY-TRAVELER-001', 'support_agent' => 'DENY', 'system_worker' => 'POLICY-SYSTEM-001'],
        'Traveler.basic:update' => ['owner' => 'POLICY-TRAVELER-001', 'collaborator' => 'POLICY-TRAVELER-001', 'traveler' => 'POLICY-TRAVELER-001', 'traveler_data_viewer' => 'POLICY-TRAVELER-001', 'support_agent' => 'DENY', 'system_worker' => 'POLICY-SYSTEM-001'],
        'Traveler.sensitive_pii:read' => ['owner' => 'POLICY-PII-VIEW-001', 'collaborator' => 'DENY', 'traveler' => 'POLICY-PII-VIEW-001', 'traveler_data_viewer' => 'POLICY-PII-VIEW-001', 'support_agent' => 'DENY', 'system_worker' => 'POLICY-SYSTEM-001'],
        'Traveler.sensitive_pii:update' => ['owner' => 'POLICY-PII-VIEW-001', 'collaborator' => 'DENY', 'traveler' => 'POLICY-PII-VIEW-001', 'traveler_data_viewer' => 'POLICY-PII-VIEW-001', 'support_agent' => 'DENY', 'system_worker' => 'POLICY-SYSTEM-001'],
        'TravelerDocument:create' => ['owner' => 'POLICY-PII-VIEW-001', 'collaborator' => 'DENY', 'traveler' => 'POLICY-PII-VIEW-001', 'traveler_data_viewer' => 'POLICY-PII-VIEW-001', 'support_agent' => 'DENY', 'system_worker' => 'POLICY-SYSTEM-001'],
        'TravelerDocument:read' => ['owner' => 'POLICY-PII-VIEW-001', 'collaborator' => 'DENY', 'traveler' => 'POLICY-PII-VIEW-001', 'traveler_data_viewer' => 'POLICY-PII-VIEW-001', 'support_agent' => 'DENY', 'system_worker' => 'POLICY-SYSTEM-001'],
        'TravelerDocument:update' => ['owner' => 'POLICY-PII-VIEW-001', 'collaborator' => 'DENY', 'traveler' => 'POLICY-PII-VIEW-001', 'traveler_data_viewer' => 'POLICY-PII-VIEW-001', 'support_agent' => 'DENY', 'system_worker' => 'POLICY-SYSTEM-001'],
        'Offer.Search:execute' => ['owner' => 'ALLOW', 'collaborator' => 'POLICY-TRIP-001', 'traveler' => 'POLICY-TRIP-001', 'traveler_data_viewer' => 'DENY', 'support_agent' => 'DENY', 'system_worker' => 'POLICY-SYSTEM-001'],
        'Offer.Search:read' => ['owner' => 'ALLOW', 'collaborator' => 'POLICY-TRIP-001', 'traveler' => 'POLICY-TRIP-001', 'traveler_data_viewer' => 'DENY', 'support_agent' => 'DENY', 'system_worker' => 'POLICY-SYSTEM-001'],
        'PlanRevision:read' => ['owner' => 'ALLOW', 'collaborator' => 'POLICY-TRIP-001', 'traveler' => 'DENY', 'traveler_data_viewer' => 'DENY', 'support_agent' => 'DENY', 'system_worker' => 'POLICY-SYSTEM-001'],
        'PlanRevision:create' => ['owner' => 'ALLOW', 'collaborator' => 'POLICY-TRIP-001', 'traveler' => 'DENY', 'traveler_data_viewer' => 'DENY', 'support_agent' => 'DENY', 'system_worker' => 'POLICY-SYSTEM-001'],
        'Proposal:read' => ['owner' => 'ALLOW', 'collaborator' => 'POLICY-TRIP-001', 'traveler' => 'DENY', 'traveler_data_viewer' => 'DENY', 'support_agent' => 'DENY', 'system_worker' => 'POLICY-SYSTEM-001'],
        'Proposal:accept' => ['owner' => 'ALLOW', 'collaborator' => 'POLICY-TRIP-001', 'traveler' => 'DENY', 'traveler_data_viewer' => 'DENY', 'support_agent' => 'DENY', 'system_worker' => 'POLICY-SYSTEM-001'],
        'Proposal:reject' => ['owner' => 'ALLOW', 'collaborator' => 'POLICY-TRIP-001', 'traveler' => 'DENY', 'traveler_data_viewer' => 'DENY', 'support_agent' => 'DENY', 'system_worker' => 'POLICY-SYSTEM-001'],
        'AuditLog:read' => ['owner' => 'POLICY-TRIP-001', 'collaborator' => 'DENY', 'traveler' => 'DENY', 'traveler_data_viewer' => 'DENY', 'support_agent' => 'DENY', 'system_worker' => 'POLICY-SYSTEM-001'],
        'Account:export' => ['owner' => 'POLICY-ACCOUNT-SELF-001', 'collaborator' => 'POLICY-ACCOUNT-SELF-001', 'traveler' => 'POLICY-ACCOUNT-SELF-001', 'traveler_data_viewer' => 'POLICY-ACCOUNT-SELF-001', 'support_agent' => 'DENY', 'system_worker' => 'DENY'],
        'Account:delete' => ['owner' => 'POLICY-ACCOUNT-SELF-001', 'collaborator' => 'POLICY-ACCOUNT-SELF-001', 'traveler' => 'POLICY-ACCOUNT-SELF-001', 'traveler_data_viewer' => 'POLICY-ACCOUNT-SELF-001', 'support_agent' => 'DENY', 'system_worker' => 'DENY'],
        'Traveler.sensitive_pii:delete' => ['owner' => 'POLICY-SUBJECT-DELETE-001', 'collaborator' => 'DENY', 'traveler' => 'POLICY-SUBJECT-DELETE-001', 'traveler_data_viewer' => 'DENY', 'support_agent' => 'DENY', 'system_worker' => 'DENY'],
        'TravelerDocument:delete' => ['owner' => 'POLICY-SUBJECT-DELETE-001', 'collaborator' => 'DENY', 'traveler' => 'POLICY-SUBJECT-DELETE-001', 'traveler_data_viewer' => 'DENY', 'support_agent' => 'DENY', 'system_worker' => 'DENY'],
        'Traveler.minor_sensitive_pii:read' => ['owner' => 'POLICY-MINOR-001', 'collaborator' => 'DENY', 'traveler' => 'POLICY-MINOR-001', 'traveler_data_viewer' => 'DENY', 'support_agent' => 'DENY', 'system_worker' => 'DENY'],
    ];

    public static function decision(string $resource, string $action, string $role): ?string
    {
        return self::DECISIONS[$resource.':'.$action][$role] ?? null;
    }
}
