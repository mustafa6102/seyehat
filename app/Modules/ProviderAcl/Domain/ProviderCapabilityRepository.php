<?php

namespace App\Modules\ProviderAcl\Domain;

interface ProviderCapabilityRepository
{
    public function find(string $providerId, string $operation, string $environment): ?ProviderCapability;

    public function save(ProviderCapability $capability): void;
}
