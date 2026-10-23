<?php

namespace App\Modules\ProviderAcl\Infrastructure\Persistence;

use App\Modules\ProviderAcl\Domain\ProviderCapability;
use App\Modules\ProviderAcl\Domain\ProviderCapabilityRepository;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

final readonly class DatabaseProviderCapabilityRepository implements ProviderCapabilityRepository
{
    public function __construct(private ConnectionInterface $database) {}

    public function find(string $providerId, string $operation, string $environment): ?ProviderCapability
    {
        $record = $this->database->table('provider_capabilities')
            ->where('provider_id', $providerId)
            ->where('operation', $operation)
            ->where('environment', $environment)
            ->first();

        if ($record === null) {
            return null;
        }

        return new ProviderCapability(
            providerId: $record->provider_id,
            operation: $record->operation,
            environment: $record->environment,
            supported: (bool) $record->supported,
            status: $record->status,
            evidence: $record->evidence,
            verifiedAt: $record->verified_at === null ? null : new DateTimeImmutable($record->verified_at),
        );
    }

    public function save(ProviderCapability $capability): void
    {
        $this->database->table('provider_capabilities')->updateOrInsert(
            [
                'provider_id' => $capability->providerId,
                'operation' => $capability->operation,
                'environment' => $capability->environment,
            ],
            [
                'supported' => $capability->supported,
                'status' => $capability->status,
                'evidence' => $capability->evidence,
                'verified_at' => $capability->verifiedAt?->format('Y-m-d H:i:s.u'),
            ],
        );
    }
}
