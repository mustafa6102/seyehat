<?php

namespace App\Modules\Accommodation\Infrastructure\Http;

use App\Modules\Accommodation\Application\AccommodationSelectionRejected;
use App\Modules\Accommodation\Application\AccommodationSelectionService;
use App\Modules\Api\Contracts\OwnershipDecision;
use App\Modules\Api\Contracts\TripRequestOwnership;
use App\Modules\Api\Contracts\TripRequestService;
use App\Modules\Authorization\Contracts\PrincipalRef;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** OP-getTripRequestAccommodationSelection, OP-selectTripRequestAccommodation, OP-reconfirmTripRequestAccommodation. */
final readonly class AccommodationSelectionController
{
    private const IDEMPOTENCY_TTL_SECONDS = 86400;

    public function __construct(
        private AccommodationSelectionService $selections,
        private TripRequestService $tripRequests,
        private CacheRepository $cache,
        private TripRequestOwnership $ownership,
    ) {}

    public function show(Request $request, string $tripRequestId): JsonResponse
    {
        $denied = $this->authorize($request, $tripRequestId);
        if ($denied !== null) {
            return $denied;
        }
        if ($this->tripRequests->get($tripRequestId) === null) {
            return $this->error('RESOURCE_NOT_FOUND', 'Seyahat talebi bulunamadı.', 404);
        }
        $selection = $this->selections->find($tripRequestId);

        return $selection === null
            ? $this->error('RESOURCE_NOT_FOUND', 'Bu seyahat talebi için henüz konaklama seçilmedi.', 404)
            : response()->json($selection);
    }

    public function store(Request $request, string $tripRequestId): JsonResponse
    {
        $denied = $this->authorize($request, $tripRequestId);
        if ($denied !== null) {
            return $denied;
        }
        $body = $request->json()->all();
        $valid = array_diff(array_keys($body), ['offer_id', 'expected_verified_at', 'accept_changed_offer']) === []
            && is_string($body['offer_id'] ?? null) && $body['offer_id'] !== '' && strlen($body['offer_id']) <= 255
            && $this->isDateTime($body['expected_verified_at'] ?? null)
            && is_bool($body['accept_changed_offer'] ?? false);

        return $this->idempotent($request, 'select', $tripRequestId, $body, $valid, fn (): array => $this->selections->select(
            $tripRequestId,
            $body['offer_id'],
            $body['expected_verified_at'],
            $body['accept_changed_offer'] ?? false,
        ));
    }

    public function reconfirm(Request $request, string $tripRequestId): JsonResponse
    {
        $denied = $this->authorize($request, $tripRequestId);
        if ($denied !== null) {
            return $denied;
        }
        $body = $request->json()->all();
        $valid = array_keys($body) === ['expected_verified_at'] && $this->isDateTime($body['expected_verified_at']);

        return $this->idempotent($request, 'reconfirm', $tripRequestId, $body, $valid, fn (): array => $this->selections->reconfirm(
            $tripRequestId,
            $body['expected_verified_at'],
        ));
    }

    /**
     * Idempotency-Key + tripRequestId: a repeated request returns the stored outcome; the same key with a different
     * request is IDEMPOTENCY_KEY_CONFLICT.
     *
     * @param  array<string, mixed>  $body
     * @param  callable(): array<string, mixed>  $action
     */
    private function idempotent(Request $request, string $operation, string $tripRequestId, array $body, bool $valid, callable $action): JsonResponse
    {
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || $key === '' || strlen($key) > 255 || ! $valid) {
            return $this->error('SCHEMA_VALIDATION_FAILED', 'İstek sözleşme doğrulamasından geçemedi.', 400);
        }
        ksort($body);
        $fingerprint = hash('sha256', $operation.'|'.$tripRequestId.'|'.json_encode($body));
        $cacheKey = 'accommodation-selection-idempotency:'.hash('sha256', $key);
        $stored = $this->cache->get($cacheKey);
        if (is_array($stored)) {
            return $stored['fingerprint'] === $fingerprint
                ? response()->json($stored['body'], $stored['status'])
                : $this->error('IDEMPOTENCY_KEY_CONFLICT', 'Aynı idempotency anahtarı farklı bir istek için kullanılamaz.', 409);
        }

        if ($this->tripRequests->get($tripRequestId) === null) {
            return $this->error('RESOURCE_NOT_FOUND', 'Seyahat talebi bulunamadı.', 404);
        }
        try {
            $response = response()->json($action());
        } catch (AccommodationSelectionRejected $rejected) {
            $response = $this->error($rejected->machineCode, $rejected->getMessage(), $rejected->httpStatus);
        }
        $this->cache->put($cacheKey, ['fingerprint' => $fingerprint, 'status' => $response->getStatusCode(), 'body' => $response->getData(true)], self::IDEMPOTENCY_TTL_SECONDS);

        return $response;
    }

    private function isDateTime(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $value) === 1
            && strtotime($value) !== false;
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
