<?php

namespace App\Modules\FlightSearch\Infrastructure\Http;

use App\Modules\Api\Contracts\OwnershipDecision;
use App\Modules\Api\Contracts\TripRequestOwnership;
use App\Modules\Api\Contracts\TripRequestService;
use App\Modules\Authorization\Contracts\PrincipalRef;
use App\Modules\FlightSearch\Application\FlightSearchService;
use App\Modules\FlightSearch\Application\IdempotencyKeyConflict;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class FlightSearchController
{
    public function __construct(
        private FlightSearchService $searches,
        private TripRequestService $tripRequests,
        private TripRequestOwnership $ownership,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || $key === '' || strlen($key) > 255) {
            return response()->json(['error' => ['code' => 'VALIDATION_ERROR', 'message_tr' => 'Idempotency-Key başlığı zorunludur.', 'correlation_id' => null, 'details' => null]], 400);
        }
        $body = $request->json()->all();
        $tripRequestId = $body['trip_request_id'] ?? null;
        if (array_keys($body) !== ['trip_request_id'] || ! is_string($tripRequestId) || $tripRequestId === '' || strlen($tripRequestId) > 255) {
            return $this->error('SCHEMA_VALIDATION_FAILED', 'İstek sözleşme doğrulamasından geçemedi.', 400);
        }

        // Owner check before the job, idempotency record or provider work exists.
        $denied = $this->authorize($request, $tripRequestId);
        if ($denied !== null) {
            return $denied;
        }

        // SCH-FlightSearchStartRequest v3.4: only an existing SEARCHABLE TripRequest may be searched.
        $tripRequest = $this->tripRequests->get($tripRequestId);
        if ($tripRequest === null) {
            return $this->error('RESOURCE_NOT_FOUND', 'Seyahat talebi bulunamadı.', 404);
        }
        if ($tripRequest->status !== 'SEARCHABLE') {
            return $this->error('TRIP_REQUEST_INCOMPLETE', 'Seyahat talebinin zorunlu alanları eksik.', 422);
        }

        try {
            $job = $this->searches->start($key, $tripRequestId);
        } catch (IdempotencyKeyConflict) {
            return $this->error('IDEMPOTENCY_KEY_CONFLICT', 'Aynı idempotency anahtarı farklı bir istek için kullanılamaz.', 409);
        }

        return response()->json(['search_id' => $job->searchId, 'status' => 'QUEUED'], 202);
    }

    /**
     * v3.16 PLT-AUTH-001 (od_impact ADR-010): 401 without a bearer principal. For the addressed TripRequest 404 precedes
     * 403; for a job the owner derives only from its persisted trip_request_id, so a job without one (legacy) or whose
     * TripRequest is gone is 403. An ownerless legacy TripRequest is 403 and never claimed.
     */
    private function authorize(Request $request, ?string $tripRequestId, bool $forJob = false): ?JsonResponse
    {
        $principal = $request->attributes->get(PrincipalRef::class);
        if (! $principal instanceof PrincipalRef) {
            return $this->error('AUTHENTICATION_REQUIRED', 'Bu işlem için kimlik doğrulaması gerekiyor.', 401);
        }
        $decision = $tripRequestId === null ? OwnershipDecision::DENIED : $this->ownership->check($principal, $tripRequestId);

        return match ($decision) {
            OwnershipDecision::ALLOWED => null,
            OwnershipDecision::NOT_FOUND => $forJob
                ? $this->error('AUTHORIZATION_DENIED', 'Bu işlem için yetkiniz bulunmuyor.', 403)
                : $this->error('RESOURCE_NOT_FOUND', 'Seyahat talebi bulunamadı.', 404),
            OwnershipDecision::DENIED => $this->error('AUTHORIZATION_DENIED', 'Bu işlem için yetkiniz bulunmuyor.', 403),
        };
    }

    private function error(string $code, string $messageTr, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message_tr' => $messageTr, 'correlation_id' => null, 'details' => null]], $status);
    }

    public function show(Request $request, string $searchId): JsonResponse
    {
        $job = $this->searches->find($searchId);
        if ($job === null) {
            return response()->json(['error' => ['code' => 'RESOURCE_NOT_FOUND', 'message_tr' => 'Uçuş arama işi bulunamadı.', 'correlation_id' => null, 'details' => null]], 404);
        }
        $denied = $this->authorize($request, $job->tripRequestId, forJob: true);
        if ($denied !== null) {
            return $denied;
        }
        $job = $this->searches->poll($searchId) ?? $job;

        $response = response()->json($job->toContract());
        // OP-pollFlightSearch 200 Retry-After: polling guidance in seconds while the job can still change. The value
        // is deployment configuration only (FLIGHT_SEARCH_POLL_INTERVAL_MS); without it no header is invented.
        $intervalMs = config('flight_search.poll_interval_ms');
        if (! $job->isSettled() && is_int($intervalMs) && $intervalMs > 0) {
            $response->header('Retry-After', (string) max(1, (int) ceil($intervalMs / 1000)));
        }

        return $response;
    }
}
