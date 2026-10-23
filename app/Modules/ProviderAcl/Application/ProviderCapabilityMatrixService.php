<?php

namespace App\Modules\ProviderAcl\Application;

use App\Modules\ProviderAcl\Contracts\ProviderCapabilityMatrix;
use App\Modules\ProviderAcl\Contracts\ProviderCapabilityView;
use App\Modules\ProviderAcl\Domain\ProviderCapabilityRepository;

final readonly class ProviderCapabilityMatrixService implements ProviderCapabilityMatrix
{
    public function __construct(private ProviderCapabilityRepository $capabilities) {}

    public function productionCapability(string $providerId, string $operation): ProviderCapabilityView
    {
        $capability = $this->capabilities->find($providerId, $operation, 'production');

        if ($capability === null) {
            return new ProviderCapabilityView(
                operation: $operation,
                supported: false,
                status: 'UNKNOWN',
                evidence: null,
                verifiedAt: null,
            );
        }

        return $capability->toProductionView();
    }
}
