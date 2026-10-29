<?php

/*
|--------------------------------------------------------------------------
| Provider resilience (PLT-REL-001, NFR-RL-001)
|--------------------------------------------------------------------------
|
| Config boundary for CFG-RETRY-TIMEOUT-CIRCUIT (decision ADR-006). Unit, default and
| range are OPEN in the SSOT and the first production flight provider is OPEN (OD-001),
| so no flight provider values are defined here. A provider without a complete,
| versioned entry is never called (fail closed).
|
| Entry shape, keyed by provider_id:
|   'version' => string (config version for audit),
|   'timeout_ms' => int,
|   'retry' => ['max_attempts' => int, 'base_delay_ms' => int, 'max_delay_ms' => int],
|   'circuit_breaker' => ['failure_threshold' => int, 'open_seconds' => int],
|   'rate_limit' => ['max_requests' => int, 'per_seconds' => int],
|
*/

return [
    'providers' => [
        /*
         * GeoNames-specific operational profile (location catalog only; not an ADR-006 global
         * resolution and not a default for other providers). Free tier per official docs:
         * 1 credit per request, 1000 credits/hour, 10000 credits/day per username.
         * Default 375 requests/hour stays below the hourly limit and keeps 24h usage
         * (9000) below the daily limit. Every retry attempt counts against the limit.
         */
        'geonames' => [
            'version' => env('GEONAMES_POLICY_VERSION', 'geonames-ops-2026-09-28'),
            'timeout_ms' => (int) env('GEONAMES_TIMEOUT_MS', 2000),
            'retry' => [
                'max_attempts' => (int) env('GEONAMES_RETRY_MAX_ATTEMPTS', 2),
                'base_delay_ms' => (int) env('GEONAMES_RETRY_BASE_DELAY_MS', 200),
                'max_delay_ms' => (int) env('GEONAMES_RETRY_MAX_DELAY_MS', 1000),
            ],
            'circuit_breaker' => [
                'failure_threshold' => (int) env('GEONAMES_CIRCUIT_FAILURE_THRESHOLD', 5),
                'open_seconds' => (int) env('GEONAMES_CIRCUIT_OPEN_SECONDS', 60),
            ],
            'rate_limit' => [
                'max_requests' => (int) env('GEONAMES_RATE_LIMIT_MAX_REQUESTS', 375),
                'per_seconds' => (int) env('GEONAMES_RATE_LIMIT_PER_SECONDS', 3600),
            ],
        ],

        /*
         * Duffel Places deploy-time operational profile (flight LOCATION DISCOVERY only; GET /places/suggestions,
         * read-only, so retries have no side effects). Scoped per CFG-RETRY-TIMEOUT-CIRCUIT (scope provider/operation,
         * versioned); not an ADR-006 resolution and not a default for flight search providers (OD-001).
         * Rate limit: Duffel documents 60 requests / 60 s (example) and 120 / 60 s (live search default), both
         * "subject to change"; 50 / 60 s stays below the lowest documented value and every retry attempt counts.
         * Circuit open time equals the documented 60 s rate-limit window; timeout/retry/backoff mirror the approved
         * GeoNames location-discovery profile.
         */
        'duffel_places' => [
            'version' => env('DUFFEL_PLACES_POLICY_VERSION', 'duffel-places-ops-2026-09-29'),
            'timeout_ms' => (int) env('DUFFEL_PLACES_TIMEOUT_MS', 2000),
            'retry' => [
                'max_attempts' => (int) env('DUFFEL_PLACES_RETRY_MAX_ATTEMPTS', 2),
                'base_delay_ms' => (int) env('DUFFEL_PLACES_RETRY_BASE_DELAY_MS', 200),
                'max_delay_ms' => (int) env('DUFFEL_PLACES_RETRY_MAX_DELAY_MS', 1000),
            ],
            'circuit_breaker' => [
                'failure_threshold' => (int) env('DUFFEL_PLACES_CIRCUIT_FAILURE_THRESHOLD', 5),
                'open_seconds' => (int) env('DUFFEL_PLACES_CIRCUIT_OPEN_SECONDS', 60),
            ],
            'rate_limit' => [
                'max_requests' => (int) env('DUFFEL_PLACES_RATE_LIMIT_MAX_REQUESTS', 50),
                'per_seconds' => (int) env('DUFFEL_PLACES_RATE_LIMIT_PER_SECONDS', 60),
            ],
        ],

        /*
         * Duffel Flights deploy-time operational profile (POST /air/offer_requests). A search creates a provider-side
         * offer request, so it is not retried (max_attempts 1). The call budget covers Duffel's per-airline wait
         * (supplier_timeout is derived from it). Rate limit: Duffel documents 60 requests / 60 s, "subject to change";
         * 20 / 60 s stays well below it. Not an ADR-006 resolution and not a default for other providers.
         */
        'duffel_flights' => [
            'version' => env('DUFFEL_FLIGHTS_POLICY_VERSION', 'duffel-flights-ops-2026-09-29'),
            'timeout_ms' => (int) env('DUFFEL_FLIGHTS_TIMEOUT_MS', 30000),
            'retry' => [
                'max_attempts' => (int) env('DUFFEL_FLIGHTS_RETRY_MAX_ATTEMPTS', 1),
                'base_delay_ms' => (int) env('DUFFEL_FLIGHTS_RETRY_BASE_DELAY_MS', 200),
                'max_delay_ms' => (int) env('DUFFEL_FLIGHTS_RETRY_MAX_DELAY_MS', 1000),
            ],
            'circuit_breaker' => [
                'failure_threshold' => (int) env('DUFFEL_FLIGHTS_CIRCUIT_FAILURE_THRESHOLD', 5),
                'open_seconds' => (int) env('DUFFEL_FLIGHTS_CIRCUIT_OPEN_SECONDS', 60),
            ],
            'rate_limit' => [
                'max_requests' => (int) env('DUFFEL_FLIGHTS_RATE_LIMIT_MAX_REQUESTS', 20),
                'per_seconds' => (int) env('DUFFEL_FLIGHTS_RATE_LIMIT_PER_SECONDS', 60),
            ],
        ],
        /*
         * duffel_stays (OD-023, accommodation_search). No values are defined here (CFG-RETRY-TIMEOUT-CIRCUIT / ADR-006):
         * the entry is complete only when the operator sets every DUFFEL_STAYS_* variable, otherwise the provider is
         * never called (fail closed).
         */
        /*
         * osm_overpass (OD-025, poi_discovery + poi_verify): the v3.15 contract-approved DEVELOPMENT profile
         * osm-overpass-dev-2026-10-06 (contracts/v3.15/registries/config.yaml, CFG-RETRY-TIMEOUT-CIRCUIT). The rate limit is
         * this application's development safety budget, not a provider quota. The production profile is OPEN (ADR-006):
         * in production the entry is null, so the provider is never called (fail closed).
         */
        /*
         * ticketmaster (OD-EVT-001-TM, v3.22): EVT-001 event discovery. GET is read-only, so retries are safe.
         * Ticketmaster documentation quotes 5000 API calls/day and 200 req/hour (non-burst); 150/3600 s stays
         * safely below the hourly limit. Timeout covers event payload + network latency.
         */
        'ticketmaster' => [
            'version' => env('TICKETMASTER_POLICY_VERSION', 'ticketmaster-ops-2026-10-28'),
            'timeout_ms' => (int) env('TICKETMASTER_TIMEOUT_MS', 8000),
            'retry' => [
                'max_attempts' => (int) env('TICKETMASTER_RETRY_MAX_ATTEMPTS', 2),
                'base_delay_ms' => (int) env('TICKETMASTER_RETRY_BASE_DELAY_MS', 500),
                'max_delay_ms' => (int) env('TICKETMASTER_RETRY_MAX_DELAY_MS', 2000),
            ],
            'circuit_breaker' => [
                'failure_threshold' => (int) env('TICKETMASTER_CIRCUIT_FAILURE_THRESHOLD', 5),
                'open_seconds' => (int) env('TICKETMASTER_CIRCUIT_OPEN_SECONDS', 120),
            ],
            'rate_limit' => [
                'max_requests' => (int) env('TICKETMASTER_RATE_LIMIT_MAX_REQUESTS', 150),
                'per_seconds' => (int) env('TICKETMASTER_RATE_LIMIT_PER_SECONDS', 3600),
            ],
        ],

        'osm_overpass' => env('APP_ENV') === 'production' ? null : [
            'version' => 'osm-overpass-dev-2026-10-06',
            'timeout_ms' => 10000,
            'retry' => ['max_attempts' => 1, 'base_delay_ms' => 1000, 'max_delay_ms' => 1000],
            'circuit_breaker' => ['failure_threshold' => 3, 'open_seconds' => 300],
            'rate_limit' => ['max_requests' => 100, 'per_seconds' => 86400],
        ],
        'duffel_stays' => [
            'version' => env('DUFFEL_STAYS_POLICY_VERSION'),
            'timeout_ms' => is_numeric(env('DUFFEL_STAYS_TIMEOUT_MS')) ? (int) env('DUFFEL_STAYS_TIMEOUT_MS') : null,
            'retry' => [
                'max_attempts' => is_numeric(env('DUFFEL_STAYS_RETRY_MAX_ATTEMPTS')) ? (int) env('DUFFEL_STAYS_RETRY_MAX_ATTEMPTS') : null,
                'base_delay_ms' => is_numeric(env('DUFFEL_STAYS_RETRY_BASE_DELAY_MS')) ? (int) env('DUFFEL_STAYS_RETRY_BASE_DELAY_MS') : null,
                'max_delay_ms' => is_numeric(env('DUFFEL_STAYS_RETRY_MAX_DELAY_MS')) ? (int) env('DUFFEL_STAYS_RETRY_MAX_DELAY_MS') : null,
            ],
            'circuit_breaker' => [
                'failure_threshold' => is_numeric(env('DUFFEL_STAYS_CIRCUIT_FAILURE_THRESHOLD')) ? (int) env('DUFFEL_STAYS_CIRCUIT_FAILURE_THRESHOLD') : null,
                'open_seconds' => is_numeric(env('DUFFEL_STAYS_CIRCUIT_OPEN_SECONDS')) ? (int) env('DUFFEL_STAYS_CIRCUIT_OPEN_SECONDS') : null,
            ],
            'rate_limit' => [
                'max_requests' => is_numeric(env('DUFFEL_STAYS_RATE_LIMIT_MAX_REQUESTS')) ? (int) env('DUFFEL_STAYS_RATE_LIMIT_MAX_REQUESTS') : null,
                'per_seconds' => is_numeric(env('DUFFEL_STAYS_RATE_LIMIT_PER_SECONDS')) ? (int) env('DUFFEL_STAYS_RATE_LIMIT_PER_SECONDS') : null,
            ],
        ],
    ],
];
