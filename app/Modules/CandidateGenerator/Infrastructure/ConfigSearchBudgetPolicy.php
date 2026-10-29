<?php

namespace App\Modules\CandidateGenerator\Infrastructure;

use App\Modules\CandidateGenerator\Contracts\SearchBudgetLimit;
use App\Modules\CandidateGenerator\Contracts\SearchBudgetPolicy;
use Illuminate\Contracts\Config\Repository;

/** Reads CFG-SEARCH-BUDGET from config/search_budget.php; an absent or incomplete entry is "not configured". */
final readonly class ConfigSearchBudgetPolicy implements SearchBudgetPolicy
{
    public function __construct(private Repository $config) {}

    public function limit(string $providerId, string $operation): ?SearchBudgetLimit
    {
        $providers = $this->config->get('search_budget.providers', []);
        $entry = is_array($providers) ? ($providers[$providerId][$operation] ?? null) : null;
        if (! is_array($entry)) {
            return null;
        }

        $maxRequests = $entry['max_requests'] ?? null;
        $version = $entry['version'] ?? null;
        if (! is_int($maxRequests) || $maxRequests < 1 || ! is_string($version) || trim($version) === '') {
            return null;
        }

        $productCap = $this->config->get('search_budget.product_cap');
        $productVersion = $this->config->get('search_budget.product_cap_version');
        if (! is_int($productCap) || $productCap < 1 || ! is_string($productVersion) || trim($productVersion) === '') {
            return null;
        }

        return new SearchBudgetLimit(min($maxRequests, $productCap), $version.'|'.$productVersion);
    }
}
