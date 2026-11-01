<?php

namespace App\Modules\TripBuilder\Infrastructure\Persistence;

use App\Modules\TripBuilder\Contracts\TripDirectory;
use Illuminate\Database\ConnectionInterface;

final readonly class DatabaseTripDirectory implements TripDirectory
{
    public function __construct(private ConnectionInterface $database) {}

    public function tripRequestIdOf(string $tripId): ?string
    {
        $tripRequestId = $this->database->table('trips')->where('trip_id', $tripId)->value('trip_request_id');

        return $tripRequestId === null ? null : (string) $tripRequestId;
    }

    public function tripIdOf(string $tripRequestId): ?string
    {
        $tripId = $this->database->table('trips')->where('trip_request_id', $tripRequestId)->value('trip_id');

        return $tripId === null ? null : (string) $tripId;
    }

    public function lock(string $tripId): bool
    {
        return $this->database->table('trips')->where('trip_id', $tripId)->lockForUpdate()->first(['trip_id']) !== null;
    }
}
