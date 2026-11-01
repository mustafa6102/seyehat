<?php

/*
|--------------------------------------------------------------------------
| RCG-001 candidate inputs
|--------------------------------------------------------------------------
|
| Every value is deployment-supplied evidence/configuration. Empty or malformed
| JSON stays unconfigured; there are no built-in hubs, routes or verdicts.
|
*/

$decode = static function (?string $value): ?array {
    if (! is_string($value) || trim($value) === '') {
        return null;
    }

    $decoded = json_decode($value, true);

    return is_array($decoded) ? $decoded : null;
};

return [
    // This deployment switch is insufficient by itself: runtime also requires the exact active contract version.
    'provider_leg_search_active' => filter_var(env('RCG_PROVIDER_LEG_SEARCH_ACTIVE', false), FILTER_VALIDATE_BOOL),
    'required_contract_version' => '3.24.0',
    'hub_places' => $decode(env('RCG_HUB_PLACES_JSON')),
    'minimum_connection_seconds' => is_numeric(env('RCG_MIN_CONNECTION_SECONDS')) ? (int) env('RCG_MIN_CONNECTION_SECONDS') : null,
    'connection_config_version' => env('RCG_CONNECTION_CONFIG_VERSION'),
    'max_results' => is_numeric(env('RCG_MAX_RESULTS')) ? (int) env('RCG_MAX_RESULTS') : null,
    'results_config_version' => env('RCG_RESULTS_CONFIG_VERSION'),

    // Ordered location ids, for example aviation catalog ids. No code default.
    'hub_ids' => $decode(env('RCG_HUB_SEEDS_JSON')),

    // Object keyed by canonical destination location id, each value an ordered list.
    'alternative_arrivals' => $decode(env('RCG_ALTERNATIVE_ARRIVALS_JSON')),

    // Object keyed by RouteCandidate::key(), then PruningCheck value.
    // Each terminal PASS/FAIL entry requires a non-empty evidence_ref.
    'pruning_evidence' => $decode(env('RCG_PRUNING_EVIDENCE_JSON')),
];
