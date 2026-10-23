<?php

/*
|--------------------------------------------------------------------------
| POI discovery (POI-001, contracts/v3.15/registries/config.yaml)
|--------------------------------------------------------------------------
|
| Approved v3.15 product values, not provider limits. A missing or out-of-range value fails discovery closed
| (GAP-POI-CRITERIA-001); the application never substitutes another number. The Overpass endpoint
| (CFG-POI-OVERPASS-ENDPOINT) is in config/services.php under osm_overpass and comes from the environment only.
|
*/

return [
    // CFG-POI-SEARCH-RADIUS: meters (Overpass around filter), range 500-20000.
    'search_radius_m' => 3000,

    // CFG-POI-MAX-RESULTS: canonical POIs returned and stored per discovery job, range 1-200.
    'max_results' => 50,

    // CFG-POI-DISCOVERY-TAG-FILTER (RESOLVED v3.15, OD-025): osm_overpass discovery scope as OSM key => allowed values. A named
    // element matching any pair is a candidate. Provider query filter only - not a canonical category, user preference, ranking
    // or OD-026 taxonomy. Changes require a versioned contract/config change; a missing/invalid value fails discovery closed.
    'discovery_tag_filter' => [
        'tourism' => ['attraction', 'museum', 'gallery', 'viewpoint', 'artwork', 'zoo', 'aquarium', 'theme_park'],
        'historic' => ['monument', 'memorial', 'castle', 'ruins', 'archaeological_site', 'fort', 'palace', 'city_gate', 'tower', 'monastery'],
    ],
];
