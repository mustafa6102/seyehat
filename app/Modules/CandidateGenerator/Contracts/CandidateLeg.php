<?php

namespace App\Modules\CandidateGenerator\Contracts;

/** One separately searched flight leg; each leg costs one provider search request. */
final readonly class CandidateLeg
{
    public function __construct(
        public string $fromId,
        public string $toId,
    ) {}
}
