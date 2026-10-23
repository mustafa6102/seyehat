<?php

namespace App\Modules\Accommodation\Infrastructure\Http;

use App\Modules\Accommodation\Application\AccommodationSearchService;
use App\Modules\Api\Contracts\OwnershipDecision;
use App\Modules\Api\Contracts\TripRequestOwnership;
use App\Modules\Api\Contracts\TripRequestService;
use App\Modules\Authorization\Contracts\PrincipalRef;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** OP-startAccommodationSearch and OP-pollAccommodationSearch (v3.14). Criteria are never taken from the request. */
final readonly class AccommodationSearchController
{
    public function __construct(
        private AccommodationSearchService $searches,
        private TripRequestService $tripRequests,
        private TripRequestOwnership $ownership,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $key = $request->header('Idempotency-Key');
        $body = $request->json()->all();
        $tripRequestId = $body['trip_request_id'] ?? null;
        if (! is_string($key) || $key === '' || strlen($key) > 255
            || array_keys($body) !== ['trip_request_id'] || ! is_string($tripRequestId) || $tripRequestId === '' || strlen($tripRequestId) > 255) {
            return $this->error('SCHEMA_VALIDATION_FAILED', 'İstek sözleşme doğrulamasından geçemedi.', 400);
        }
        // Owner check before the job, idempotency record or provider work exists.
        $denied = $this->authorize($request, $tripRequestId);
        if ($denied !== null) {
            return $denied;
        }
        $tripRequest = $this->tripRequests->get($tripRequestId);
        if ($tripRequest === null) {
            return $this->error('RESOURCE_NOT_FOUND', 'Seyahat talebi bulunamadı.', 404);
        }
        if ($tripRequest->status !== 'SEARCHABLE') {
            return $this->error('TRIP_REQUEST_INCOMPLETE', 'Seyahat talebinin zorunlu alanları eksik.', 422);
        }
        try {
            $job = $this->searches->start($key, $tripRequestId);
        } catch (DomainException) {
            return $this->error('IDEMPOTENCY_KEY_CONFLICT', 'Aynı idempotency anahtarı farklı bir istek için kullanılamaz.', 409);
        }

        return response()->json(['search_id' => $job['search_id'], 'status' => $job['status']], 202);
    }

    public function show(Request $request, string $searchId): JsonResponse
    {
        $job = $this->searches->poll($searchId);
        // The job's persisted trip_request_id is the only ownership source; nothing is returned before the owner check.
        $denied = $job === null ? null : $this->authorize($request, $job['trip_request_id'], forJob: true);
        if ($denied !== null) {
            return $denied;
        }

        return $job === null
            ? $this->error('RESOURCE_NOT_FOUND', 'Konaklama arama işi bulunamadı.', 404)
            : response()->json($job);
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
}
