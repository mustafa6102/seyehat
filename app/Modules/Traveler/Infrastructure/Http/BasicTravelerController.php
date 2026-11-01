<?php

namespace App\Modules\Traveler\Infrastructure\Http;

use App\Modules\Authorization\Contracts\AccessDenied;
use App\Modules\Authorization\Contracts\PrincipalRef;
use App\Modules\Traveler\Contracts\BasicTraveler;
use App\Modules\Traveler\Contracts\BasicTravelerRejected;
use App\Modules\Traveler\Contracts\TravelerStorageClosed;
use App\Modules\Traveler\Contracts\TripTravelerEnrollment;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Bearer channel of the Basic Traveler operations; reachable only behind EnsureBasicTravelerActive. */
final readonly class BasicTravelerController
{
    public function __construct(private TripTravelerEnrollment $travelers) {}

    public function store(Request $request, string $tripRequestId): JsonResponse
    {
        return $this->respond(function () use ($request, $tripRequestId): JsonResponse {
            $traveler = $this->travelers->enroll(
                $this->principal($request),
                $tripRequestId,
                $request->header('Idempotency-Key'),
                $request->json()->all(),
            );

            return response()->json($traveler->toContract(), 201)
                ->header('Location', route('trip-requests.travelers.show', ['tripRequestId' => $tripRequestId, 'travelerId' => $traveler->id], false));
        });
    }

    public function index(Request $request, string $tripRequestId): JsonResponse
    {
        return $this->respond(fn (): JsonResponse => response()->json([
            'items' => array_map(
                fn (BasicTraveler $traveler): array => $traveler->toContract(),
                $this->travelers->roster($this->principal($request), $tripRequestId)->travelers,
            ),
        ]));
    }

    public function show(Request $request, string $tripRequestId, string $travelerId): JsonResponse
    {
        return $this->respond(fn (): JsonResponse => response()->json(
            $this->travelers->find($this->principal($request), $tripRequestId, $travelerId)->toContract(),
        ));
    }

    private function principal(Request $request): PrincipalRef
    {
        return $request->attributes->get(PrincipalRef::class);
    }

    /** @param Closure(): JsonResponse $operation */
    private function respond(Closure $operation): JsonResponse
    {
        try {
            return $operation();
        } catch (AccessDenied $exception) {
            return $this->error(AccessDenied::MACHINE_CODE, $exception->getMessage(), 403);
        } catch (BasicTravelerRejected $exception) {
            return $this->error($exception->machineCode, $exception->getMessage(), $exception->httpStatus);
        } catch (TravelerStorageClosed) {
            return $this->error('SCHEMA_VALIDATION_FAILED', 'İstek sözleşme doğrulamasından geçemedi.', 400);
        }
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'error' => ['code' => $code, 'message_tr' => $message, 'correlation_id' => null, 'details' => null],
        ], $status);
    }
}
