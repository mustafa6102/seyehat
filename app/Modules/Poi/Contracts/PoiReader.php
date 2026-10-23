<?php

namespace App\Modules\Poi\Contracts;

/** Public read access to the stored canonical SCH-Poi (v3.15) for other modules; no provider call is made. */
interface PoiReader
{
    /** @return array<string, mixed>|null SCH-Poi exactly as stored */
    public function find(string $poiId): ?array;
}
