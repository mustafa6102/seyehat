<?php

namespace App\Modules\Traveler\Infrastructure\Persistence;

use App\Modules\Traveler\Contracts\TravelerSubjectRequestRefused;
use App\Modules\Traveler\Contracts\TravelerSubjectRequests;
use App\Modules\Traveler\Domain\Traveler;
use App\Modules\Traveler\Domain\TravelerSubjectRequestStore;
use App\Modules\TripBuilder\Contracts\TripDirectory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * is_open is 1 while a request is open and NULL afterwards, so the unique index allows one open request per Traveler and
 * type while closed ones accumulate. Requests have no foreign key: the record of a deletion outlives the deleted row.
 */
final readonly class DatabaseTravelerSubjectRequestStore implements TravelerSubjectRequestStore
{
    private const RECEIVED = 'RECEIVED';

    private const TABLE = 'traveler_subject_requests';

    public function __construct(
        private ConnectionInterface $database,
        private TripDirectory $trips,
    ) {}

    public function open(string $id, string $tripRequestId, string $tripId, string $travelerId, string $type, string $subjectId, string $createdAt): string
    {
        try {
            $this->database->table(self::TABLE)->insert([
                'id' => $id,
                'trip_request_id' => $tripRequestId,
                'trip_id' => $tripId,
                'traveler_id' => $travelerId,
                'request_type' => $type,
                'status' => self::RECEIVED,
                'is_open' => 1,
                'subject_id' => $subjectId,
                'created_at' => $createdAt,
            ]);
        } catch (UniqueConstraintViolationException) {
            return (string) $this->database->table(self::TABLE)
                ->where('traveler_id', $travelerId)->where('request_type', $type)->where('is_open', 1)->value('id');
        }

        return $id;
    }

    public function tripOfBasicTraveler(string $travelerId): ?string
    {
        $tripId = $this->database->table('travelers')->where('id', $travelerId)
            ->where('age_category', Traveler::UNGUARDED_AGE_CATEGORY)->whereNotNull('idempotency_key')->value('trip_id');

        return $tripId === null ? null : (string) $tripId;
    }

    public function openTypes(string $travelerId): array
    {
        return $this->database->table(self::TABLE)->where('traveler_id', $travelerId)->where('is_open', 1)
            ->pluck('request_type')->map(fn (mixed $type): string => (string) $type)->all();
    }

    public function pending(int $limit): array
    {
        return $this->database->table(self::TABLE)->where('is_open', 1)->orderBy('created_at')->limit($limit)
            ->get(['id', 'request_type', 'created_at'])
            ->map(fn (object $row): array => ['id' => (string) $row->id, 'type' => (string) $row->request_type, 'created_at' => (string) $row->created_at])
            ->all();
    }

    public function close(string $requestId, string $status, bool $deleteTraveler, string $operator, string $decisionRef, string $processedAt): array
    {
        $tripId = $this->database->table(self::TABLE)->where('id', $requestId)->where('is_open', 1)->value('trip_id');
        if ($tripId === null) {
            throw TravelerSubjectRequestRefused::notOpen();
        }

        try {
            return $this->database->transaction(function () use ($requestId, $tripId, $status, $deleteTraveler, $operator, $decisionRef, $processedAt): array {
                // Same first lock as enrollment, so a removal and a concurrent capacity count stay ordered.
                $this->trips->lock((string) $tripId);
                $row = $this->database->table(self::TABLE)->where('id', $requestId)->where('is_open', 1)->lockForUpdate()->first();
                if ($row === null) {
                    throw TravelerSubjectRequestRefused::notOpen();
                }

                if ($deleteTraveler && $row->request_type === TravelerSubjectRequests::DELETE) {
                    // Only rows this feature created: a legacy row's content and Trip link are not vouched for.
                    $this->database->table('travelers')->where('id', $row->traveler_id)->where('trip_id', $tripId)
                        ->where('age_category', Traveler::UNGUARDED_AGE_CATEGORY)->whereNotNull('idempotency_key')->delete();
                }
                $this->database->table(self::TABLE)->where('id', $requestId)->update([
                    'status' => $status,
                    'is_open' => null,
                    'processed_at' => $processedAt,
                    'processed_by' => $operator,
                    'decision_ref' => $decisionRef,
                ]);

                return ['trip_request_id' => (string) $row->trip_request_id, 'traveler_id' => (string) $row->traveler_id, 'type' => (string) $row->request_type, 'subject_id' => (string) $row->subject_id];
            });
        } catch (QueryException $exception) {
            // A restricting foreign key: encrypted fields or a subject binding still depend on this Traveler.
            if ((string) $exception->getCode() === '23000') {
                throw TravelerSubjectRequestRefused::linkedSensitiveRecords();
            }

            throw $exception;
        }
    }
}
