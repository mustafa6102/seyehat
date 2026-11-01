<?php

/*
|--------------------------------------------------------------------------
| Search budget (RCG-001, NFR-SCL-001)
|--------------------------------------------------------------------------
|
| Config boundary for CFG-SEARCH-BUDGET (type integer, unit request, scope provider/operation,
| decision ADR-006). Provider quotas remain OPEN; v3.22 defines only a product ceiling.
| There is no provider fallback: a provider/operation without a complete, versioned entry gets no
| candidate search request (fail closed).
|
| Entry shape, keyed by provider_id then operation:
|   '<provider_id>' => ['search' => ['version' => string, 'max_requests' => int]],
|
*/

$providerBudgets = env('RCG_PROVIDER_SEARCH_BUDGETS_JSON');
$providerBudgets = is_string($providerBudgets) && trim($providerBudgets) !== ''
    ? json_decode($providerBudgets, true)
    : null;

return [
    // v3.22 delegated product ceiling; a provider-specific approved entry is still mandatory.
    'product_cap' => 4,
    'product_cap_version' => 'rcg-product-cap-3.22',
    'providers' => is_array($providerBudgets) ? $providerBudgets : [],
];
