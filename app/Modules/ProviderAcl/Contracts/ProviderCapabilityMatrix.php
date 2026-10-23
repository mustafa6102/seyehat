<?php

namespace App\Modules\ProviderAcl\Contracts;

interface ProviderCapabilityMatrix
{
    public function productionCapability(string $providerId, string $operation): ProviderCapabilityView;
}
