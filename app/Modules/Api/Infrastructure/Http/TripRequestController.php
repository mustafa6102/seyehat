<?php

namespace App\Modules\Api\Infrastructure\Http;

use App\Modules\Api\Contracts\IncompleteTripRequest;
use App\Modules\Api\Contracts\InvalidTripRequest;
use App\Modules\Api\Contracts\OwnershipDecision;
use App\Modules\Api\Contracts\TripRequestOwnership;
use App\Modules\Api\Contracts\TripRequestService;
use App\Modules\Authorization\Contracts\PrincipalRef;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final readonly class TripRequestController
{
    private const WRITABLE_FIELDS = ['status', 'origin_place', 'destination_place', 'journey_type', 'outbound_date', 'return_date', 'traveler_count', 'passenger_composition', 'preferences', 'room_count'];

    public function __construct(private TripRequestService $tripRequests, private TripRequestOwnership $ownership) {}

    public function store(Request $request): JsonResponse
    {
        // SCH-TripRequest v3.9 writable fields; date_window is read-only legacy data and any other field is rejected.
        $unknownFields = array_values(array_diff(array_keys($request->all()), self::WRITABLE_FIELDS));
        if ($unknownFields !== []) {
            return $this->error('SCHEMA_VALIDATION_FAILED', 'İstek sözleşme doğrulamasından geçemedi.', 400, [
                'fields' => $unknownFields,
            ]);
        }

        $validator = Validator::make($request->all(), [
            'status' => ['required', Rule::in(['DRAFT', 'SEARCHABLE'])],
            'origin_place' => ['sometimes', 'array:kind,value,iana_timezone'],
            'origin_place.kind' => ['sometimes', 'string'],
            'origin_place.value' => ['sometimes', 'string'],
            'origin_place.iana_timezone' => ['sometimes', 'timezone:all'],
            'destination_place' => ['sometimes', 'array:kind,value,iana_timezone'],
            'destination_place.kind' => ['sometimes', 'string'],
            'destination_place.value' => ['sometimes', 'string'],
            'destination_place.iana_timezone' => ['sometimes', 'timezone:all'],
            'journey_type' => ['nullable', 'string'],
            'outbound_date' => ['nullable', 'array'],
            'return_date' => ['nullable', 'array'],
            'traveler_count' => ['sometimes', 'integer', 'min:1'],
            'passenger_composition' => ['nullable', 'array:adults,children,infants'],
            'passenger_composition.adults' => ['required_with:passenger_composition', 'integer', 'min:0'],
            'passenger_composition.children' => ['required_with:passenger_composition', 'integer', 'min:0'],
            'passenger_composition.infants' => ['required_with:passenger_composition', 'integer', 'min:0'],
            'preferences' => ['nullable', 'array'],
            'room_count' => ['sometimes', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return $this->error('SCHEMA_VALIDATION_FAILED', 'İstek sözleşme doğrulamasından geçemedi.', 400, [
                'fields' => array_keys($validator->errors()->toArray()),
            ]);
        }

        // bearerAuth (v3.16): the owner is the authenticated principal set by AuthenticateBearer, never request input.
        $owner = $request->attributes->get(PrincipalRef::class);
        if (! $owner instanceof PrincipalRef) {
            return $this->error('AUTHENTICATION_REQUIRED', 'Bu işlem için kimlik doğrulaması gerekiyor.', 401);
        }

        try {
            $created = $this->tripRequests->create($validator->validated(), $owner);
        } catch (InvalidTripRequest $exception) {
            // RULE-TRIP-001-04/05 are contract rules of SCH-TripRequest v3.9 (RC-VALIDATION-SCHEMA).
            return $this->error('SCHEMA_VALIDATION_FAILED', 'İstek sözleşme doğrulamasından geçemedi.', 400, [
                'fields' => $exception->invalidFields,
            ]);
        } catch (IncompleteTripRequest $exception) {
            return $this->error('TRIP_REQUEST_INCOMPLETE', $exception->getMessage(), 422, [
                'missing_fields' => $exception->missingFields,
            ]);
        }

        return response()->json($created->tripRequest->toContract())
            ->header('Location', route('trip-requests.show', ['tripRequestId' => $created->id], false));
    }

    /** OP-getTripRequest (v3.16 PLT-AUTH-001): bearer (middleware) -> TripRequest exists (404) -> owner (403) -> body. */
    public function show(Request $request, string $tripRequestId): JsonResponse
    {
        $principal = $request->attributes->get(PrincipalRef::class);
        if (! $principal instanceof PrincipalRef) {
            return $this->error('AUTHENTICATION_REQUIRED', 'Bu işlem için kimlik doğrulaması gerekiyor.', 401);
        }
        $decision = $this->ownership->check($principal, $tripRequestId);
        if ($decision === OwnershipDecision::DENIED) {
            return $this->error('AUTHORIZATION_DENIED', 'Bu işlem için yetkiniz bulunmuyor.', 403);
        }
        $tripRequest = $decision === OwnershipDecision::ALLOWED ? $this->tripRequests->get($tripRequestId) : null;

        if ($tripRequest === null) {
            return $this->error('RESOURCE_NOT_FOUND', 'Seyahat talebi bulunamadı.', 404);
        }

        return response()->json($tripRequest->toContract());
    }

    /** @param array<string, mixed>|null $details */
    private function error(string $code, string $message, int $status, ?array $details = null): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message_tr' => $message,
                'correlation_id' => null,
                'details' => $details,
            ],
        ], $status);
    }
}
