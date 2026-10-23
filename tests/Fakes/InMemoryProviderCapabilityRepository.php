<?php

namespace Tests\Fakes;

use App\Modules\ProviderAcl\Domain\ProviderCapability;
use App\Modules\ProviderAcl\Domain\ProviderCapabilityRepository;

final class InMemoryProviderCapabilityRepository implements ProviderCapabilityRepository
{
    /** @var array<string, ProviderCapability> */
    private array $capabilities = [];

    public function find(string $providerId, string $operation, string $environment): ?ProviderCapability
    {
        return $this->capabilities[$this->key($providerId, $operation, $environment)] ?? null;
    }

    public function save(ProviderCapability $capability): void
    {
        $this->capabilities[$this->key(
            $capability->providerId,
            $capability->operation,
            $capability->environment,
        )] = $capability;
    }

    private function key(string $providerId, string $operation, string $environment): string
    {
        return implode('|', [$providerId, $operation, $environment]);
    }
}
