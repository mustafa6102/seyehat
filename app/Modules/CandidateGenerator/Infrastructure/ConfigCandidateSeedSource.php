<?php

namespace App\Modules\CandidateGenerator\Infrastructure;

use App\Modules\CandidateGenerator\Contracts\CandidateSeedSource;
use Illuminate\Contracts\Config\Repository;

final readonly class ConfigCandidateSeedSource implements CandidateSeedSource
{
    public function __construct(private Repository $config) {}

    public function hubIds(): ?array
    {
        return $this->ids($this->config->get('self_transfer.hub_ids'));
    }

    public function alternativeArrivalIds(string $destinationId): ?array
    {
        $map = $this->config->get('self_transfer.alternative_arrivals');
        if (! is_array($map) || ! array_key_exists($destinationId, $map)) {
            return null;
        }

        return $this->ids($map[$destinationId]);
    }

    /** @return list<string>|null */
    private function ids(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }

        $ids = [];
        foreach ($value as $id) {
            if (! is_string($id) || trim($id) === '') {
                return null;
            }
            $id = trim($id);
            if (! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
