<?php

namespace App\Modules\TripBuilder\Application;

use App\Modules\Api\Contracts\TripRequestService;
use App\Modules\Event\Contracts\EventReader;
use App\Modules\Poi\Contracts\PoiReader;
use App\Modules\TripBuilder\Infrastructure\Persistence\DatabaseTripPlanRepository;
use App\Shared\Outbox\Contracts\OutboxEvent;
use App\Shared\Outbox\Contracts\OutboxWriter;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use LogicException;

/**
 * TRIP-BLD-001 v3.16 plan increment: SCH-TripPlan read, unscheduled FLEXIBLE POI add and item removal (OD-021, OD-027);
 * v3.18 DAY-001 manual day assignment and in-day ordering (OD-029 A) with the derived day_frame; v3.20 adds only a
 * user-provided, explicitly UNVERIFIED visit duration (OD-030 A). No time or feasibility is ever computed.
 * Callers have already authenticated and authorized against the TripRequest owner. Every successful mutation is one
 * transaction: [Trip on first mutation] + previous CURRENT -> SUPERSEDED + new immutable PlanRevision (with its
 * Idempotency-Key) + EVT-PlanRevisionCreated outbox record (ADR-003). Nothing is persisted on any refusal.
 */
final readonly class TripPlanService
{
    /** FR-TRIP-BLD-001-02 reason for user add/remove (contracts/registries/reason_error.yaml). */
    public const REASON_CODE = 'RC-DECISION-TRANSITION-APPLIED';

    public function __construct(
        private DatabaseTripPlanRepository $plans,
        private PoiReader $pois,
        private EventReader $eventReader,
        private OutboxWriter $outbox,
        private ConnectionInterface $database,
        private TripRequestService $tripRequests,
    ) {}

    /**
     * OP-getTripRequestPlan. Never creates a Trip.
     *
     * @return array{body: array<string, mixed>, etag: string|null}
     */
    public function read(string $tripRequestId): array
    {
        $trip = $this->plans->tripFor($tripRequestId);
        if ($trip === null) {
            return ['body' => ['trip_request_id' => $tripRequestId, 'trip' => null, 'revision' => null, 'day_frame' => $this->dayFrame($tripRequestId)->toContract()], 'etag' => null];
        }

        return $this->result($tripRequestId, $trip, $this->plans->currentRevision($trip['trip_id']));
    }

    /**
     * Immutable revision history for the already-authorized owner. Reading history never creates or mutates a Trip.
     *
     * @return list<array<string, mixed>>
     */
    public function history(string $tripRequestId): array
    {
        $trip = $this->plans->tripFor($tripRequestId);

        return $trip === null ? [] : $this->plans->revisions($trip['trip_id']);
    }

    /**
     * OP-addTripPlanPoiItem: the snapshot is the stored canonical SCH-Poi, unchanged (ADR-005); no provider call.
     *
     * @return array{body: array<string, mixed>, etag: string|null}
     *
     * @throws PlanMutationRejected
     */

    public function addPoi(string $tripRequestId, string $idempotencyKey, PlanPrecondition $precondition, string $poiId): array
    {
        $fingerprint = $this->fingerprint('add', $tripRequestId, $poiId, $precondition);
        $replay = $this->replay($tripRequestId, $idempotencyKey, $fingerprint);
        if ($replay !== null) {
            return $replay;
        }
        $poi = $this->pois->find($poiId);
        if ($poi === null) {
            throw PlanMutationRejected::notFound('Gezilecek yer bulunamadı.');
        }

        $businessStatus = $poi['business_status']['value'] ?? 'UNKNOWN';
        if (in_array($businessStatus, ['CLOSED_TEMPORARILY', 'CLOSED_PERMANENTLY'], true)) {
            throw PlanMutationRejected::notFound('Kapalı olarak bildirilen gezilecek yer plana eklenemez.');
        }

        $item = ['item_id' => (string) Str::uuid(), 'item_type' => 'POI', 'constraint_type' => 'FLEXIBLE', 'poi_snapshot' => $poi, 'day_assignment' => null, 'planning_duration' => null];

        return $this->commit($tripRequestId, $idempotencyKey, $fingerprint, $precondition, true,
            fn (array $items): array => [[...$items, $item], [$item['item_id']], [], [], []]);
    }

    public function addEvent(string $tripRequestId, string $idempotencyKey, PlanPrecondition $precondition, string $eventId): array
    {
        $fingerprint = $this->fingerprint('add-event', $tripRequestId, $eventId, $precondition);
        $replay = $this->replay($tripRequestId, $idempotencyKey, $fingerprint);
        if ($replay !== null) {
            return $replay;
        }
        $event = $this->eventReader->find($eventId);
        if ($event === null) {
            throw PlanMutationRejected::notFound('Etkinlik bulunamadı.');
        }

        $status = $event['status'] ?? null;
        $ticketStatus = $event['ticket_status'] ?? null;
        $startsAt = $event['starts_at'] ?? null;
        $timezone = $event['iana_timezone'] ?? null;
        $isFreshFact = static fn (mixed $fact): bool => is_array($fact)
            && ($fact['verification_status'] ?? null) === 'FRESH'
            && is_string($fact['expires_at'] ?? null)
            && strtotime($fact['expires_at']) !== false
            && strtotime($fact['expires_at']) > time();

        if (! $isFreshFact($status) || ($status['value'] ?? null) !== 'SCHEDULED') {
            throw PlanMutationRejected::notFound('İptal edilmiş veya güncelliği doğrulanamayan etkinlik plana eklenemez.');
        }
        if (! $isFreshFact($ticketStatus) || ($ticketStatus['value'] ?? null) !== 'AVAILABLE') {
            throw PlanMutationRejected::notFound('Bilet uygunluğu doğrulanamayan etkinlik plana eklenemez.');
        }
        if (! $isFreshFact($startsAt) || ! is_string($startsAt['value'] ?? null) || strtotime($startsAt['value']) === false
            || ! is_string($timezone) || ! in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw PlanMutationRejected::notFound('Başlangıç zamanı ve saat dilimi doğrulanamayan etkinlik plana eklenemez.');
        }

        $item = ['item_id' => (string) Str::uuid(), 'item_type' => 'EVENT', 'constraint_type' => 'FIXED', 'event_snapshot' => $event, 'day_assignment' => null, 'planning_duration' => null];

        return $this->commit($tripRequestId, $idempotencyKey, $fingerprint, $precondition, true,
            fn (array $items): array => [[...$items, $item], [$item['item_id']], [], [], []]);
    }

    /**
     * OP-removeTripPlanItem.
     *
     * @return array{body: array<string, mixed>, etag: string|null}
     *
     * @throws PlanMutationRejected
     */
    public function removeItem(string $tripRequestId, string $idempotencyKey, PlanPrecondition $precondition, string $itemId): array
    {
        $fingerprint = $this->fingerprint('remove', $tripRequestId, $itemId, $precondition);
        $replay = $this->replay($tripRequestId, $idempotencyKey, $fingerprint);
        if ($replay !== null) {
            return $replay;
        }

        return $this->commit($tripRequestId, $idempotencyKey, $fingerprint, $precondition, false, function (array $items) use ($itemId): array {
            $removed = $this->find($items, $itemId);
            // The rest of the removed item's day closes the gap (AC-DAY-001-07); relative order is unchanged.
            $remaining = $this->withoutPosition(array_values(array_filter($items, fn (array $item): bool => $item['item_id'] !== $itemId)), $removed['day_assignment'] ?? null);

            return [$remaining, [], [$itemId], [], []];
        });
    }

    /**
     * OP-assignTripPlanItemDay (v3.18): $date null removes the assignment; otherwise the item is placed at $position (default:
     * end) of a day_frame day and the other items of the old and new day are renumbered 1..n in their relative order.
     *
     * @return array{body: array<string, mixed>, etag: string|null}
     *
     * @throws PlanMutationRejected
     */
    public function assignDay(string $tripRequestId, string $idempotencyKey, PlanPrecondition $precondition, string $itemId, ?string $date, ?int $position): array
    {
        $fingerprint = $this->fingerprint('assign-day', $tripRequestId, json_encode([$itemId, $date, $position], JSON_THROW_ON_ERROR), $precondition);
        $replay = $this->replay($tripRequestId, $idempotencyKey, $fingerprint);
        if ($replay !== null) {
            return $replay;
        }
        $frame = $date === null ? null : $this->dayFrame($tripRequestId);

        return $this->commit($tripRequestId, $idempotencyKey, $fingerprint, $precondition, false, function (array $items) use ($itemId, $date, $position, $frame): array {
            $item = $this->find($items, $itemId);
            if ($frame !== null && $frame->status !== DayFrame::AVAILABLE) {
                throw PlanMutationRejected::dayFrameUnavailable();
            }
            if ($frame !== null && ! $frame->contains((string) $date)) {
                throw PlanMutationRejected::dayAssignmentInvalid();
            }
            // Take the item out of its old day first, so both days stay 1..n.
            $items = $this->withoutPosition(array_map(
                fn (array $other): array => $other['item_id'] === $itemId ? array_merge($other, ['day_assignment' => null]) : $other,
                $items,
            ), $item['day_assignment'] ?? null);
            $assignment = null;
            if ($date !== null) {
                $taken = count(array_filter($items, fn (array $other): bool => ($other['day_assignment']['date'] ?? null) === $date));
                $position ??= $taken + 1;
                if ($position < 1 || $position > $taken + 1) {
                    throw PlanMutationRejected::dayAssignmentInvalid();
                }
                $assignment = ['date' => $date, 'position' => $position, 'feasibility_status' => 'NOT_EVALUATED'];
            }
            $items = array_map(function (array $other) use ($itemId, $assignment): array {
                if ($other['item_id'] === $itemId) {
                    return array_merge($other, ['day_assignment' => $assignment]);
                }
                $current = $other['day_assignment'] ?? null;
                if ($assignment !== null && $current !== null && $current['date'] === $assignment['date'] && $current['position'] >= $assignment['position']) {
                    return array_merge($other, ['day_assignment' => array_merge($current, ['position' => $current['position'] + 1])]);
                }

                return $other;
            }, $items);

            return [$items, [], [], [$itemId], []];
        });
    }

    /** OD-030 A: user-provided duration only; no provider evidence, time, opening-hours or feasibility evaluation. */
    public function setDuration(string $tripRequestId, string $idempotencyKey, PlanPrecondition $precondition, string $itemId, int $minutes): array
    {
        $fingerprint = $this->fingerprint('set-duration', $tripRequestId, json_encode([$itemId, $minutes], JSON_THROW_ON_ERROR), $precondition);
        $replay = $this->replay($tripRequestId, $idempotencyKey, $fingerprint);
        if ($replay !== null) {
            return $replay;
        }

        return $this->commit($tripRequestId, $idempotencyKey, $fingerprint, $precondition, false, function (array $items) use ($itemId, $minutes): array {
            $item = $this->find($items, $itemId);
            if (($item['item_type'] ?? null) !== 'POI') {
                throw PlanMutationRejected::notFound('Ziyaret süresi desteklenen plan öğesi bulunamadı.');
            }
            $items = array_map(
                fn (array $other): array => $other['item_id'] === $itemId
                    ? array_merge($other, ['planning_duration' => ['minutes' => $minutes, 'source' => 'USER_PROVIDED', 'verification_status' => 'UNVERIFIED']])
                    : $other,
                $items,
            );

            return [$items, [], [], [], [$itemId]];
        });
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     *
     * @throws PlanMutationRejected
     */
    private function find(array $items, string $itemId): array
    {
        foreach ($items as $item) {
            if ($item['item_id'] === $itemId) {
                return $item;
            }
        }

        throw PlanMutationRejected::notFound('Plan öğesi bulunamadı.');
    }

    /**
     * Closes the gap a left position leaves in its day: later items of that day move up by one.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array{date: string, position: int}|null  $left
     * @return list<array<string, mixed>>
     */
    private function withoutPosition(array $items, ?array $left): array
    {
        if ($left === null) {
            return $items;
        }

        return array_map(function (array $item) use ($left): array {
            $current = $item['day_assignment'] ?? null;

            return $current !== null && $current['date'] === $left['date'] && $current['position'] > $left['position']
                ? array_merge($item, ['day_assignment' => array_merge($current, ['position' => $current['position'] - 1])])
                : $item;
        }, $items);
    }

    /** RULE-DAY-001-04: derived from the TripRequest on every call, never stored. */
    private function dayFrame(string $tripRequestId): DayFrame
    {
        try {
            return DayFrame::from($this->tripRequests->get($tripRequestId));
        } catch (LogicException) {
            // An incomplete DRAFT cannot be represented as a TripRequestView.
            return DayFrame::from(null);
        }
    }

    /**
     * @param  Closure(list<array<string, mixed>>): array{0: list<array<string, mixed>>, 1: list<string>, 2: list<string>, 3: list<string>, 4: list<string>}  $change
     * @return array{body: array<string, mixed>, etag: string|null}
     */
    private function commit(string $tripRequestId, string $key, string $fingerprint, PlanPrecondition $precondition, bool $mayCreateTrip, Closure $change): array
    {
        try {
            return $this->database->transaction(function () use ($tripRequestId, $key, $fingerprint, $precondition, $mayCreateTrip, $change): array {
                $trip = $this->plans->tripFor($tripRequestId, lock: true);
                $current = null;
                if ($trip === null) {
                    if (! $mayCreateTrip) {
                        throw PlanMutationRejected::notFound('Plan bulunamadı.');
                    }
                    if (! $precondition->ifNoneMatchAny) {
                        throw PlanMutationRejected::staleRevision();
                    }
                    // TRN-PLANREVISION-02; trips.trip_request_id is unique, so a concurrent first mutation cannot add a second Trip.
                    $trip = $this->plans->createTrip($tripRequestId);
                } else {
                    $current = $this->plans->currentRevision($trip['trip_id'], lock: true);
                    if ($precondition->ifNoneMatchAny || $current === null || $current['version'] !== $precondition->ifMatchVersion) {
                        throw PlanMutationRejected::staleRevision();
                    }
                }

                [$items, $added, $removed, $moved, $updated] = $change($current['items'] ?? []);
                $version = ($current['version'] ?? 0) + 1;
                if ($current !== null) {
                    // TRN-PLANREVISION-01
                    $this->plans->supersede($trip['trip_id'], $current['version']);
                }
                $revision = $this->plans->insertRevision($trip['trip_id'], $version, $items, [
                    'base_version' => $current['version'] ?? null,
                    'added_item_ids' => $added,
                    'removed_item_ids' => $removed,
                    'moved_item_ids' => $moved,
                    'updated_item_ids' => $updated,
                    'reason_code' => self::REASON_CODE,
                ], $key, $fingerprint);
                // EVT-PlanRevisionCreated: identifiers only (no principal, token or provider data); ordering is ADR-008 (OPEN).
                $this->outbox->append(new OutboxEvent($trip['trip_id'], (string) $version));

                return $this->result($tripRequestId, $trip, $revision);
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent first mutation, a concurrent commit of the same version, or the same key committed concurrently.
            return $this->replay($tripRequestId, $key, $fingerprint) ?? throw PlanMutationRejected::staleRevision();
        }
    }

    /**
     * Same key + same request returns the revision the key created (no new revision); same key + other request is 409.
     *
     * @return array{body: array<string, mixed>, etag: string|null}|null
     */
    private function replay(string $tripRequestId, string $key, string $fingerprint): ?array
    {
        $trip = $this->plans->tripFor($tripRequestId);
        $stored = $trip === null ? null : $this->plans->revisionByIdempotencyKey($trip['trip_id'], $key);
        if ($stored === null) {
            return null;
        }
        if (! hash_equals($stored['fingerprint'], $fingerprint)) {
            throw PlanMutationRejected::idempotencyConflict();
        }

        return $this->result($tripRequestId, $trip, $stored['revision']);
    }

    private function fingerprint(string $operation, string $tripRequestId, string $target, PlanPrecondition $precondition): string
    {
        return hash('sha256', json_encode([$operation, $tripRequestId, $target, $precondition->fingerprintPart()], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $trip
     * @param  array<string, mixed>|null  $revision
     * @return array{body: array<string, mixed>, etag: string|null}
     */
    private function result(string $tripRequestId, array $trip, ?array $revision): array
    {
        return [
            'body' => ['trip_request_id' => $tripRequestId, 'trip' => $trip, 'revision' => $revision, 'day_frame' => $this->dayFrame($tripRequestId)->toContract()],
            'etag' => $revision === null ? null : PlanPrecondition::etag($revision['version']),
        ];
    }
}
