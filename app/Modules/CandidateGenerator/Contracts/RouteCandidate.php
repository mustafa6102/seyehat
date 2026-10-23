<?php

namespace App\Modules\CandidateGenerator\Contracts;

use InvalidArgumentException;

/**
 * A route shape to evaluate; it carries no provider facts (no price, availability or schedule).
 * At most one hub exists by construction (RULE-RCG-001-01).
 */
final readonly class RouteCandidate
{
    public function __construct(
        public CandidateKind $kind,
        public string $originId,
        public string $destinationId,
        public ?string $hubId = null,
        public ?string $arrivalId = null,
    ) {
        if ($originId === '' || $destinationId === '' || $originId === $destinationId) {
            throw new InvalidArgumentException('Origin and destination must be distinct, non-empty ids.');
        }

        $valid = match ($kind) {
            CandidateKind::DIRECT => $hubId === null && $arrivalId === null,
            CandidateKind::ONE_HUB => $arrivalId === null && $this->isDistinctPoint($hubId),
            CandidateKind::ALTERNATIVE_ARRIVAL => $hubId === null && $this->isDistinctPoint($arrivalId),
        };

        if (! $valid) {
            throw new InvalidArgumentException("Unsupported {$kind->value} candidate shape.");
        }
    }

    /** @return list<CandidateLeg> */
    public function legs(): array
    {
        return match ($this->kind) {
            CandidateKind::DIRECT => [new CandidateLeg($this->originId, $this->destinationId)],
            CandidateKind::ONE_HUB => [
                new CandidateLeg($this->originId, $this->hubId),
                new CandidateLeg($this->hubId, $this->destinationId),
            ],
            // The onward ground link to the destination is not a provider search here (OD-003).
            CandidateKind::ALTERNATIVE_ARRIVAL => [new CandidateLeg($this->originId, $this->arrivalId)],
        };
    }

    public function key(): string
    {
        return implode(':', array_filter([$this->kind->value, $this->hubId ?? $this->arrivalId]));
    }

    private function isDistinctPoint(?string $id): bool
    {
        return $id !== null && $id !== '' && $id !== $this->originId && $id !== $this->destinationId;
    }
}
