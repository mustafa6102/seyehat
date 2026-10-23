<?php

/*
|--------------------------------------------------------------------------
| Search budget (RCG-001, NFR-SCL-001)
|--------------------------------------------------------------------------
|
| Config boundary for CFG-SEARCH-BUDGET (type integer, unit request, scope provider/operation,
| decision ADR-006). Default and range are OPEN in the SSOT, so no value is defined here and
| there is no fallback: a provider/operation without a complete, versioned entry gets no
| candidate search request (fail closed).
|
| Entry shape, keyed by provider_id then operation:
|   '<provider_id>' => ['search' => ['version' => string, 'max_requests' => int]],
|
*/

return [
    'providers' => [],
];
