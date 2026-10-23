<?php

namespace App\Modules\ProviderAcl\Infrastructure\Adapters;

use App\Modules\ProviderAcl\Contracts\ProviderCapabilityMatrix;

final readonly class ProviderAdapterCapabilityGate
{
    public function __construct(private ProviderCapabilityMatrix $matrix) {}

    public function allowsProductionOperation(string $providerId, string $operation): bool
    {
        return $this->matrix->productionCapability($providerId, $operation)->supported;
    }
}
