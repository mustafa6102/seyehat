<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Location catalog for the TRIP-001 UI (https://www.geonames.org/export/, CC BY 4.0).
    // Inactive until a username is set and a provider_resilience policy exists (ADR-006).
    'geonames' => [
        'username' => env('GEONAMES_USERNAME'),
        'base_url' => env('GEONAMES_BASE_URL', 'https://secure.geonames.org'),
        'min_query_length' => (int) env('GEONAMES_MIN_QUERY_LENGTH', 2),
        'max_query_length' => (int) env('GEONAMES_MAX_QUERY_LENGTH', 80),
        'max_rows' => (int) env('GEONAMES_MAX_ROWS', 10),
    ],

    // Flight location discovery (Duffel Places, OD-022 v3.8). Token is server-side only.
    // Inactive until a token is set and a provider_resilience policy for duffel_places exists (ADR-006).
    'duffel' => [
        'access_token' => env('DUFFEL_ACCESS_TOKEN'),
        'base_url' => env('DUFFEL_BASE_URL', 'https://api.duffel.com'),
        'version' => env('DUFFEL_VERSION', 'v2'),
        'min_query_length' => 2,
        'max_query_length' => 80,
        'max_results' => 10,
        // passenger_policy applies only to legacy count-only TripRequests. New passenger_composition is never derived.
        'flights' => [
            'passenger_policy' => env('DUFFEL_FLIGHTS_PASSENGER_POLICY'),
            'max_offers' => (int) env('DUFFEL_FLIGHTS_MAX_OFFERS', 20),
        ],
        // duffel_stays (OD-023): CFG-ACCOMMODATION-SEARCH-MAX-PROPERTIES - search results whose rates are fetched (one
        // fetch_all_rates call each). Value OPEN (ADR-006): no default; unset or invalid means accommodation search fails closed.
        'stays' => [
            'max_properties' => is_numeric(env('DUFFEL_STAYS_MAX_PROPERTIES')) ? (int) env('DUFFEL_STAYS_MAX_PROPERTIES') : null,
        ],
    ],

    // ADR-010 / v3.16 bearerAuth: the single trusted OpenID Connect issuer (CFG-AUTH-OIDC-ISSUER, https, exact iss) and the
    // expected audience (CFG-AUTH-OIDC-AUDIENCE). Environment only, no defaults: unset or invalid values reject every token.
    'oidc' => [
        'issuer' => env('OIDC_ISSUER'),
        'audience' => env('OIDC_AUDIENCE'),
        // Browser login (Authorization Code + PKCE): client id, exact redirect URI (path /giris/donus) and, only for a
        // confidential client, a secret. No defaults; missing values make the web login unavailable (fail closed).
        'client_id' => env('OIDC_CLIENT_ID'),
        'client_secret' => env('OIDC_CLIENT_SECRET'),
        'redirect_uri' => env('OIDC_REDIRECT_URI'),
    ],

    // EVT-001 / OD-EVT-001-TM (v3.22): Ticketmaster Discovery API v2. API key is server-side only; never logged or exposed.
    // approved_hosts drives InventoryDecision::event CTA validation; no default host (CFG-INVENTORY-CTA, OD-P8-002).
    'ticketmaster' => [
        'api_key' => env('TICKETMASTER_API_KEY'),
        'base_url' => env('TICKETMASTER_BASE_URL', 'https://app.ticketmaster.com/discovery/v2'),
        'approved_hosts' => array_filter(array_map('trim', explode(',', (string) env('TICKETMASTER_APPROVED_HOSTS', 'www.ticketmaster.com,ticketmaster.com')))),
        'max_results' => (int) env('TICKETMASTER_MAX_RESULTS', 20),
        'freshness_seconds' => (int) env('TICKETMASTER_FRESHNESS_SECONDS', 86400),
    ],

    // osm_overpass (OD-025, v3.15): CFG-POI-OVERPASS-ENDPOINT comes from the environment only; there is no default. The
    // public overpass-api.de instance is accepted only outside production (development only).
    'osm_overpass' => [
        'endpoint' => env('OSM_OVERPASS_ENDPOINT'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
