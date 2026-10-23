<?php

namespace App\Modules\TripBuilder\Application;

use App\Modules\Poi\Contracts\PoiReader;
use App\Modules\TripBuilder\Infrastructure\Persistence\DatabaseTripPlanRepository;
use App\Shared\Outbox\Contracts\OutboxEvent;
use App\Shared\Outbox\Contracts\OutboxWriter;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * TRIP-BLD-001 v3.16 plan increment: SCH-TripPlan read, unscheduled FLEXIBLE POI add and item removal (OD-021, OD-027).
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
        private OutboxWriter $outbox,
        private ConnectionInterface $database,
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
            return ['body' => ['trip_request_id' => $tripRequestId, 'trip' => null, 'revision' => null], 'etag' => null];
        }

        return $this->result($tripRequestId, $trip, $this->plans->currentRevision($trip['trip_id']));
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
        $item = ['item_id' => (string) Str::uuid(), 'item_type' => 'POI', 'constraint_type' => 'FLEXIBLE', 'poi_snapshot' => $poi];

        return $this->commit($tripRequestId, $idempotencyKey, $fingerprint, $precondition, true,
            fn (array $items): array => [[...$items, $item], [$item['item_id']], []]);
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
            $remaining = array_values(array_filter($items, fn (array $item): bool => $item['item_id'] !== $itemId));
            if (count($remaining) === count($items)) {
                throw PlanMutationRejected::notFound('Plan öğesi bulunamadı.');
            }

            return [$remaining, [], [$itemId]];
        });
    }

    /**
     * @param  Closure(list<array<string, mixed>>): array{0: list<array<string, mixed>>, 1: list<string>, 2: list<string>}  $change
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

                [$items, $added, $removed] = $change($current['items'] ?? []);
                $version = ($current['version'] ?? 0) + 1;
                if ($current !== null) {
                    // TRN-PLANREVISION-01
                    $this->plans->supersede($trip['trip_id'], $current['version']);
                }
                $revision = $this->plans->insertRevision($trip['trip_id'], $version, $items, [
                    'base_version' => $current['version'] ?? null,
                    'added_item_ids' => $added,
                    'removed_item_ids' => $removed,
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
            'body' => ['trip_request_id' => $tripRequestId, 'trip' => $trip, 'revision' => $revision],
            'etag' => $revision === null ? null : PlanPrecondition::etag($revision['version']),
        ];
    }
}
