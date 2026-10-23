<?php

namespace App\Modules\CandidateGenerator\Contracts;

/** Opaque pass-through of a leg search; the generator never reads or derives provider facts from it. */
final readonly class LegSearchResult
{
    public function __construct(
        public bool $answered,
        public ?string $resultRef = null,
    ) {}
}
