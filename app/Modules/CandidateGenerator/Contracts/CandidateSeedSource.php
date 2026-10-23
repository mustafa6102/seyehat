<?php

namespace App\Modules\CandidateGenerator\Contracts;

/** Hub seed and alternative-arrival inputs. Null means not configured; nothing is ever invented. */
interface CandidateSeedSource
{
    /** @return list<string>|null ordered hub location ids */
    public function hubIds(): ?array;

    /** @return list<string>|null ordered alternative arrival location ids for the destination */
    public function alternativeArrivalIds(string $destinationId): ?array;
}
