<?php

namespace App\Modules\ProviderAcl\Infrastructure\Resilience;

use App\Modules\ProviderAcl\Contracts\ResiliencePolicies;
use App\Modules\ProviderAcl\Domain\Resilience\ResiliencePolicy;
use Illuminate\Contracts\Config\Repository;

final readonly class ConfigResiliencePolicies implements ResiliencePolicies
{
    public function __construct(private Repository $config) {}

    public function for(string $providerId): ?ResiliencePolicy
    {
        $entry = $this->providers()[$providerId] ?? null;

        return ResiliencePolicy::fromConfig(is_array($entry) ? $entry : null);
    }

    public function configuredProviderIds(): array
    {
        return array_values(array_filter(
            array_map('strval', array_keys($this->providers())),
            fn (string $providerId): bool => $this->for($providerId) !== null,
        ));
    }

    /** @return array<array-key, mixed> */
    private function providers(): array
    {
        $providers = $this->config->get('provider_resilience.providers', []);

        return is_array($providers) ? $providers : [];
    }
}
