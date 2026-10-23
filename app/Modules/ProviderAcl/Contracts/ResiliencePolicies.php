<?php

namespace App\Modules\ProviderAcl\Contracts;

use App\Modules\ProviderAcl\Domain\Resilience\ResiliencePolicy;

interface ResiliencePolicies
{
    /** Null when the provider's policy is not (fully) configured. */
    public function for(string $providerId): ?ResiliencePolicy;

    /** @return list<string> */
    public function configuredProviderIds(): array;
}
