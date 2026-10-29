<?php

namespace App\Modules\TripBuilder\Infrastructure\Http;

use App\Modules\Api\Contracts\OwnershipDecision;
use App\Modules\Api\Contracts\TripRequestOwnership;
use App\Modules\Api\Contracts\TripRequestService;
use App\Modules\Authorization\Contracts\PrincipalRef;
use App\Modules\TripBuilder\Application\PlanMutationRejected;
use App\Modules\TripBuilder\Application\PlanPrecondition;
use App\Modules\TripBuilder\Application\TripPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * OP-getTripRequestPlan, OP-addTripPlanPoiItem, OP-removeTripPlanItem (v3.16), OP-assignTripPlanItemDay (v3.18) and
 * OP-setTripPlanItemDuration (v3.20). Check order (x-authorization): bearer
 * authentication (middleware) -> TripRequest exists (404) -> owner (403) -> request validation -> OD-027 processing.
 */
final readonly class TripPlanController
{
    public function __construct(
        private TripPlanService $plans,
        private TripRequestOwnership $ownership,
        private TripRequestService $tripRequests,
    ) {}

    public function show(Request $request, string $tripRequestId): JsonResponse
    {
        $denied = $this->authorize($request, $tripRequestId);
        if ($denied !== null) {
            return $denied;
        }

        return $this->respond($this->plans->read($tripRequestId), 200);
    }

    public function store(Request $request, string $tripRequestId): JsonResponse
    {
        $denied = $this->authorize($request, $tripRequestId);
        if ($denied !== null) {
            return $denied;
        }
        $key = $request->header('Idempotency-Key');
        $body = $request->json()->all();
        $poiId = $body['poi_id'] ?? null;
        $precondition = PlanPrecondition::fromHeaders($request->header('If-Match'), $request->header('If-None-Match'));
        // Body is exactly {poi_id}: snapshot, owner, provider data, day/time, duration, FIXED or feasibility are never accepted.
        if (! $this->validKey($key) || array_keys($body) !== ['poi_id'] || ! is_string($poiId) || $poiId === '' || strlen($poiId) > 255
            || $precondition === null) {
            return $this->error('SCHEMA_VALIDATION_FAILED', 'İstek sözleşme doğrulamasından geçemedi.', 400);
        }
        if (! $this->isSearchable($tripRequestId)) {
            return $this->error('TRIP_REQUEST_INCOMPLETE', 'Seyahat talebinin zorunlu alanları eksik.', 422);
        }

        return $this->mutate(fn (): array => $this->plans->addPoi($tripRequestId, $key, $precondition, $poiId), 201);
    }

    /** OP-addTripPlanEventItem (EVT-001 v3.22). Body is exactly {event_id}; snapshot, day/time, duration are never accepted. */
    public function storeEvent(Request $request, string $tripRequestId): JsonResponse
    {
        $denied = $this->authorize($request, $tripRequestId);
        if ($denied !== null) {
            return $denied;
        }
        $key = $request->header('Idempotency-Key');
        $body = $request->json()->all();
        $eventId = $body['event_id'] ?? null;
        $precondition = PlanPrecondition::fromHeaders($request->header('If-Match'), $request->header('If-None-Match'));
        if (! $this->validKey($key) || array_keys($body) !== ['event_id'] || ! is_string($eventId) || $eventId === '' || strlen($eventId) > 255
            || $precondition === null) {
            return $this->error('SCHEMA_VALIDATION_FAILED', 'İstek sözleşme doğrulamasından geçemedi.', 400);
        }
        if (! $this->isSearchable($tripRequestId)) {
            return $this->error('TRIP_REQUEST_INCOMPLETE', 'Seyahat talebinin zorunlu alanları eksik.', 422);
        }

        return $this->mutate(fn (): array => $this->plans->addEvent($tripRequestId, $key, $precondition, $eventId), 201);
    }

    public function destroy(Request $request, string $tripRequestId, string $itemId): JsonResponse
    {
        $denied = $this->authorize($request, $tripRequestId);
        if ($denied !== null) {
            return $denied;
        }
        $key = $request->header('Idempotency-Key');
        $precondition = PlanPrecondition::fromHeaders($request->header('If-Match'), $request->header('If-None-Match'));
        if (! $this->validKey($key) || $precondition === null || $precondition->ifNoneMatchAny || $request->json()->all() !== []
            || $itemId === '' || strlen($itemId) > 255) {
            return $this->error('SCHEMA_VALIDATION_FAILED', 'İstek sözleşme doğrulamasından geçemedi.', 400);
        }

        return $this->mutate(fn (): array => $this->plans->removeItem($tripRequestId, $key, $precondition, $itemId), 200);
    }

    /**
     * OP-assignTripPlanItemDay (v3.18, OD-029 A). Body is exactly {date} or {date, position}: date null removes the
     * assignment (no position then); a time, duration, feasibility or snapshot field is never accepted.
     */
    public function assignDay(Request $request, string $tripRequestId, string $itemId): JsonResponse
    {
        $denied = $this->authorize($request, $tripRequestId);
        if ($denied !== null) {
            return $denied;
        }
        $key = $request->header('Idempotency-Key');
        $body = $request->json()->all();
        $precondition = PlanPrecondition::fromHeaders($request->header('If-Match'), $request->header('If-None-Match'));
        $date = $body['date'] ?? null;
        $position = $body['position'] ?? null;
        $keys = array_keys($body);
        sort($keys);
        $validDate = $date === null || (is_string($date) && preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/', $date, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]));
        if (! $this->validKey($key) || $precondition === null || $precondition->ifNoneMatchAny || $itemId === '' || strlen($itemId) > 255
            || ! in_array($keys, [['date'], ['date', 'position']], true) || ! $validDate
            || ($keys === ['date', 'position'] && ($date === null || ! is_int($position) || $position < 1))) {
            return $this->error('SCHEMA_VALIDATION_FAILED', 'İstek sözleşme doğrulamasından geçemedi.', 400);
        }

        return $this->mutate(fn (): array => $this->plans->assignDay($tripRequestId, $key, $precondition, $itemId, $date, $position), 200);
    }

    /** OP-setTripPlanItemDuration (OD-030 A). Body is exactly {minutes}; source/status are server constants. */
    public function setDuration(Request $request, string $tripRequestId, string $itemId): JsonResponse
    {
        $denied = $this->authorize($request, $tripRequestId);
        if ($denied !== null) {
            return $denied;
        }
        $key = $request->header('Idempotency-Key');
        $body = $request->json()->all();
        $minutes = $body['minutes'] ?? null;
        $precondition = PlanPrecondition::fromHeaders($request->header('If-Match'), $request->header('If-None-Match'));
        if (! $this->validKey($key) || $precondition === null || $precondition->ifNoneMatchAny || $itemId === '' || strlen($itemId) > 255
            || array_keys($body) !== ['minutes'] || ! is_int($minutes) || $minutes < 1) {
            return $this->error('SCHEMA_VALIDATION_FAILED', 'İstek sözleşme doğrulamasından geçemedi.', 400);
        }

        return $this->mutate(fn (): array => $this->plans->setDuration($tripRequestId, $key, $precondition, $itemId, $minutes), 200);
    }

    /** 404 for an unknown TripRequest, 403 for another principal or an ownerless legacy TripRequest (never claimed). */
    private function authorize(Request $request, string $tripRequestId): ?JsonResponse
    {
        $principal = $request->attributes->get(PrincipalRef::class);
        if (! $principal instanceof PrincipalRef) {
            return $this->error('AUTHENTICATION_REQUIRED', 'Bu işlem için kimlik doğrulaması gerekiyor.', 401);
        }

        return match ($this->ownership->check($principal, $tripRequestId)) {
            OwnershipDecision::ALLOWED => null,
            OwnershipDecision::NOT_FOUND => $this->error('RESOURCE_NOT_FOUND', 'Seyahat talebi bulunamadı.', 404),
            OwnershipDecision::DENIED => $this->error('AUTHORIZATION_DENIED', 'Bu işlem için yetkiniz bulunmuyor.', 403),
        };
    }

    /** @param  callable(): array{body: array<string, mixed>, etag: string|null}  $operation */
    private function mutate(callable $operation, int $status): JsonResponse
    {
        try {
            return $this->respond($operation(), $status);
        } catch (PlanMutationRejected $rejection) {
            return $this->error($rejection->machineCode, $rejection->getMessage(), $rejection->httpStatus);
        }
    }

    private function isSearchable(string $tripRequestId): bool
    {
        try {
            return $this->tripRequests->get($tripRequestId)?->status === 'SEARCHABLE';
        } catch (\LogicException) {
            // An incomplete DRAFT cannot be represented as a TripRequestView; it is by definition not SEARCHABLE.
            return false;
        }
    }

    private function validKey(mixed $key): bool
    {
        return is_string($key) && $key !== '' && strlen($key) <= 255;
    }

    /** @param  array{body: array<string, mixed>, etag: string|null}  $result */
    private function respond(array $result, int $status): JsonResponse
    {
        $response = response()->json($result['body'], $status);

        return $result['etag'] === null ? $response : $response->header('ETag', $result['etag']);
    }

    private function error(string $code, string $messageTr, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message_tr' => $messageTr, 'correlation_id' => null, 'details' => null]], $status);
    }
}
