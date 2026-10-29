<?php

namespace App\Modules\HowTo\Infrastructure\Http;

use App\Modules\Api\Contracts\OwnershipDecision;
use App\Modules\Api\Contracts\TripRequestOwnership;
use App\Modules\Authorization\Contracts\PrincipalRef;
use App\Modules\HowTo\Application\HowGuideRejected;
use App\Modules\HowTo\Application\HowGuideService;
use App\Modules\TripBuilder\Application\PlanPrecondition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class HowGuideController
{
    public function __construct(private readonly HowGuideService $guides, private readonly TripRequestOwnership $ownership) {}

    public function show(Request $request, string $tripRequestId, string $itemId): JsonResponse
    {
        return $this->handle($request, $tripRequestId, fn (PrincipalRef $principal, int $version): array => $this->guides->read($tripRequestId, $itemId, $version));
    }

    public function refresh(Request $request, string $tripRequestId, string $itemId): JsonResponse
    {
        return $this->handle($request, $tripRequestId, function (PrincipalRef $principal, int $version) use ($request, $tripRequestId, $itemId): array {
            $key = $request->header('Idempotency-Key');
            if (! is_string($key) || trim($key) === '' || strlen($key) > 255) {
                throw HowGuideRejected::validation('Idempotency-Key başlığı zorunludur ve en fazla 255 karakter olabilir.');
            }

            return $this->guides->refresh($principal, $tripRequestId, $itemId, $version, $key);
        });
    }

    /** @param callable(PrincipalRef, int): array{body: array<string, mixed>, status: int, etag: string} $operation */
    private function handle(Request $request, string $tripRequestId, callable $operation): JsonResponse
    {
        $principal = $request->attributes->get(PrincipalRef::class);
        if (! $principal instanceof PrincipalRef) {
            return $this->error('AUTHENTICATION_REQUIRED', 'Bu işlem için kimlik doğrulaması gerekiyor.', 401);
        }
        $decision = $this->ownership->check($principal, $tripRequestId);
        if ($decision !== OwnershipDecision::ALLOWED) {
            return $decision === OwnershipDecision::NOT_FOUND
                ? $this->error('RESOURCE_NOT_FOUND', 'Seyahat talebi bulunamadı.', 404)
                : $this->error('AUTHORIZATION_DENIED', 'Bu işlem için yetkiniz bulunmuyor.', 403);
        }
        $precondition = PlanPrecondition::fromHeaders($request->header('If-Match'), $request->header('If-None-Match'));
        if ($precondition === null || $precondition->ifMatchVersion === null || $precondition->ifNoneMatchAny) {
            return $this->error('VALIDATION_ERROR', 'Güncel plan sürümü If-Match: "<version>" başlığıyla gönderilmelidir.', 400);
        }

        try {
            $result = $operation($principal, $precondition->ifMatchVersion);

            return response()->json($result['body'], $result['status'])->header('ETag', $result['etag']);
        } catch (HowGuideRejected $rejection) {
            return $this->error($rejection->machineCode, $rejection->getMessage(), $rejection->httpStatus);
        }
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message_tr' => $message, 'correlation_id' => null, 'details' => null]], $status);
    }
}
