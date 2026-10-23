<?php

namespace App\Modules\CandidateGenerator\Contracts;

final readonly class CheckResult
{
    public function __construct(
        public PruningCheck $check,
        public CheckVerdict $verdict,
        public ?string $evidenceRef = null,
        /** Decision/config id (or gap token) explaining an UNKNOWN verdict. */
        public ?string $blocker = null,
    ) {}

    public static function pass(PruningCheck $check, string $evidenceRef): self
    {
        return new self($check, CheckVerdict::PASS, $evidenceRef);
    }

    public static function fail(PruningCheck $check, string $evidenceRef): self
    {
        return new self($check, CheckVerdict::FAIL, $evidenceRef);
    }

    public static function unknown(PruningCheck $check, string $blocker): self
    {
        return new self($check, CheckVerdict::UNKNOWN, null, $blocker);
    }
}
