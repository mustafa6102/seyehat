<?php

namespace App\Modules\Traveler\Infrastructure\Persistence;

use App\Modules\Traveler\Contracts\BasicTraveler;
use App\Modules\Traveler\Contracts\BasicTravelerRejected;
use App\Modules\Traveler\Contracts\TravelerStorageClosed;
use App\Modules\Traveler\Domain\Traveler;
use App\Modules\Traveler\Domain\TravelerEnrollmentStore;
use App\Modules\TripBuilder\Contracts\TripDirectory;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use LogicException;

/**
 * The Trip row lock must be the transaction's first statement: under InnoDB REPEATABLE READ an earlier plain read would
 * fix the snapshot and COUNT could miss a committed row, overfilling the capacity. (trip_id, idempotency_key) is the
 * second defense. Reads never select the sensitive columns.
 */
final readonly class DatabaseTravelerEnrollmentStore implements TravelerEnrollmentStore
{
    public function __construct(
        private ConnectionInterface $database,
        private TripDirectory $trips,
    ) {}

    public function enroll(Traveler $traveler, string $idempotencyKey, string $fingerprint, ?int $adultCapacity, string $createdAt): BasicTraveler
    {
        if ($traveler->requiresProtectedStorage()) {
            throw new TravelerStorageClosed;
        }
        if ($this->database->transactionLevel() > 0) {
            throw new LogicException('Enrollment must own its transaction: an outer one may already hold a stale snapshot.');
        }

        try {
            return $this->database->transaction(function () use ($traveler, $idempotencyKey, $fingerprint, $adultCapacity, $createdAt): BasicTraveler {
                if (! $this->trips->lock($traveler->tripId)) {
                    throw BasicTravelerRejected::tripNotInitialized();
                }

                $replayed = $this->replay($traveler->tripId, $idempotencyKey, $fingerprint);
                if ($replayed !== null) {
                    return $replayed;
                }

                if ($adultCapacity === null || $this->hasUnverifiedRows($traveler->tripId)
                    || $this->basicRows($traveler->tripId)->count() >= $adultCapacity) {
                    throw BasicTravelerRejected::capacityUnavailable();
                }

                $this->database->table('travelers')->insert([
                    'id' => $traveler->id,
                    'trip_id' => $traveler->tripId,
                    'age_category' => $traveler->ageCategory,
                    'nationalities' => '[]',
                    'travel_documents' => '[]',
                    'held_visas' => '[]',
                    'residence_permits' => '[]',
                    'created_at' => $createdAt,
                    'idempotency_key' => $idempotencyKey,
                    'request_fingerprint' => $fingerprint,
                ]);

                return $this->basic((object) ['id' => $traveler->id, 'trip_id' => $traveler->tripId, 'age_category' => $traveler->ageCategory, 'created_at' => $createdAt]);
            });
        } catch (UniqueConstraintViolationException) {
            return $this->replay($traveler->tripId, $idempotencyKey, $fingerprint) ?? throw BasicTravelerRejected::idempotencyConflict();
        }
    }

    /** A row from outside this store: its caller-supplied trip_id neither proves nor excludes a real traveler on this Trip. */
    public function hasUnverifiedRows(string $tripId): bool
    {
        return $this->rows($tripId)->whereNull('idempotency_key')->exists();
    }

    public function listForTrip(string $tripId): array
    {
        return $this->basicRows($tripId)->orderBy('created_at')->orderBy('id')->get()
            ->map(fn (object $row): BasicTraveler => $this->basic($row))->all();
    }

    public function findInTrip(string $tripId, string $travelerId): ?BasicTraveler
    {
        $row = $this->basicRows($tripId)->where('id', $travelerId)->first();

        return $row === null ? null : $this->basic($row);
    }

    private function replay(string $tripId, string $key, string $fingerprint): ?BasicTraveler
    {
        $row = $this->rows($tripId)->where('idempotency_key', $key)
            ->first(['id', 'trip_id', 'age_category', 'created_at', 'request_fingerprint']);
        if ($row === null) {
            return null;
        }
        if (! hash_equals((string) $row->request_fingerprint, $fingerprint)) {
            throw BasicTravelerRejected::idempotencyConflict();
        }

        return $this->basic($row);
    }

    private function rows(string $tripId): Builder
    {
        return $this->database->table('travelers')->where('trip_id', $tripId);
    }

    /**
     * Only rows created through this store: a legacy row's trip_id was caller-supplied, so it proves no membership, and it
     * may hold a category or data this projection must not expose. Such a row closes the Trip to new adults instead.
     */
    private function basicRows(string $tripId): Builder
    {
        return $this->rows($tripId)->where('age_category', Traveler::UNGUARDED_AGE_CATEGORY)->whereNotNull('idempotency_key')
            ->select(['id', 'trip_id', 'age_category', 'created_at']);
    }

    private function basic(object $row): BasicTraveler
    {
        return new BasicTraveler(
            (string) $row->id,
            (string) $row->trip_id,
            (string) $row->age_category,
            (new DateTimeImmutable((string) $row->created_at, new DateTimeZone('UTC')))->format(DATE_ATOM),
        );
    }
}
