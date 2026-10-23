<?php

namespace App\Modules\Traveler\Infrastructure\Http;

use App\Modules\Traveler\Contracts\TravelerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final readonly class TravelerController
{
    private const VERIFICATION_STATUSES = ['FRESH', 'STALE', 'EXPIRED', 'UNVERIFIED'];

    private const FIELDS = [
        'id',
        'trip_id',
        'age_category',
        'nationalities',
        'travel_documents',
        'held_visas',
        'residence_permits',
    ];

    public function __construct(private TravelerService $travelers) {}

    public function store(Request $request): JsonResponse
    {
        $unknownFields = array_diff(array_keys($request->all()), self::FIELDS);

        if ($unknownFields !== []) {
            return $this->error('SCHEMA_VALIDATION_FAILED', 'İstek sözleşme doğrulamasından geçemedi.', 400, [
                'fields' => array_values($unknownFields),
            ]);
        }

        $validator = Validator::make($request->all(), [
            'id' => ['required', 'string'],
            'trip_id' => ['required', 'string'],
            'age_category' => ['required', 'string'],
            'nationalities' => ['present', 'list'],
            'nationalities.*' => ['string'],
            'travel_documents' => ['present', 'list'],
            'travel_documents.*' => ['array:document_type,verification_status'],
            'travel_documents.*.document_type' => ['required', 'string'],
            'travel_documents.*.verification_status' => ['required', Rule::in(self::VERIFICATION_STATUSES)],
            'held_visas' => ['present', 'list'],
            'held_visas.*' => ['array:verification_status'],
            'held_visas.*.verification_status' => ['required', Rule::in(self::VERIFICATION_STATUSES)],
            'residence_permits' => ['present', 'list'],
            'residence_permits.*' => ['array:verification_status'],
            'residence_permits.*.verification_status' => ['required', Rule::in(self::VERIFICATION_STATUSES)],
        ]);

        if ($validator->fails()) {
            return $this->error('SCHEMA_VALIDATION_FAILED', 'İstek sözleşme doğrulamasından geçemedi.', 400, [
                'fields' => array_keys($validator->errors()->toArray()),
            ]);
        }

        $traveler = $this->travelers->create($validator->validated());

        return response()->json($traveler->toContract())
            ->header('Location', route('travelers.show', ['travelerId' => $traveler->id], false));
    }

    public function show(string $travelerId): JsonResponse
    {
        $traveler = $this->travelers->get($travelerId);

        if ($traveler === null) {
            return $this->error('RESOURCE_NOT_FOUND', 'Yolcu bulunamadı.', 404);
        }

        return response()->json($traveler->toContract());
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
