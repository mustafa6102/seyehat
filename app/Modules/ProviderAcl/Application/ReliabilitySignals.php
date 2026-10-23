<?php

namespace App\Modules\ProviderAcl\Application;

use App\Modules\ProviderAcl\Contracts\ResiliencePolicies;
use Illuminate\Queue\Failed\CountableFailedJobProvider;
use Illuminate\Queue\Failed\FailedJobProviderInterface;

/**
 * FR-PLT-REL-001-01 SLI read port: circuit state per provider and DLQ depth. In-process only;
 * no OpenAPI/metrics contract defines an external surface for these values yet.
 */
final readonly class ReliabilitySignals
{
    public function __construct(
        private ResiliencePolicies $policies,
        private CircuitBreaker $breaker,
        private FailedJobProviderInterface $deadLetters,
    ) {}

    /** @return array<string, string> provider_id => CLOSED|OPEN|HALF_OPEN */
    public function circuitStates(): array
    {
        $states = [];
        foreach ($this->policies->configuredProviderIds() as $providerId) {
            $states[$providerId] = $this->breaker->state($providerId, $this->policies->for($providerId))->value;
        }

        return $states;
    }

    /** Null when the configured failed-job store cannot be counted. */
    public function dlqDepth(): ?int
    {
        return $this->deadLetters instanceof CountableFailedJobProvider ? $this->deadLetters->count() : null;
    }
}
