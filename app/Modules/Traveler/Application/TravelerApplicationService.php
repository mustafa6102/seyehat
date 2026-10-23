<?php

namespace App\Modules\Traveler\Application;

use App\Modules\Traveler\Contracts\TravelerService;
use App\Modules\Traveler\Domain\SensitiveDataAccessLog;
use App\Modules\Traveler\Domain\Traveler;
use App\Modules\Traveler\Domain\TravelerRepository;

final readonly class TravelerApplicationService implements TravelerService
{
    public function __construct(
        private TravelerRepository $travelers,
        private SensitiveDataAccessLog $accessLog,
    ) {}

    public function create(array $input): Traveler
    {
        $traveler = $this->travelers->save(Traveler::fromContract($input));
        $this->accessLog->record('create', $traveler);

        return $traveler;
    }

    public function get(string $id): ?Traveler
    {
        $traveler = $this->travelers->find($id);
        if ($traveler !== null) {
            $this->accessLog->record('read', $traveler);
        }

        return $traveler;
    }
}
