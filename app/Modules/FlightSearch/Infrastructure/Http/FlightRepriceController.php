<?php

namespace App\Modules\FlightSearch\Infrastructure\Http;

use App\Modules\FlightSearch\Application\FlightRepriceService;
use App\Modules\FlightSearch\Application\FlightSelectionService;
use App\Modules\FlightSearch\Application\OfferNotFound;
use Illuminate\Http\JsonResponse;

final readonly class FlightRepriceController
{
    public function __construct(private FlightRepriceService $repricing, private FlightSelectionService $selections) {}

    public function store(string $offerId): JsonResponse
    {
        try {
            $result = $this->repricing->reprice($offerId);
        } catch (OfferNotFound) {
            return response()->json([
                'error' => [
                    'code' => 'RESOURCE_NOT_FOUND',
                    'message_tr' => 'Yeniden fiyatlanacak uçuş teklifi bulunamadı.',
                    'correlation_id' => null,
                    'details' => null,
                ],
            ], 404);
        }

        // v3.13: latest server-side verification for selection; CHANGED moves a CURRENT selection to RECONFIRMATION_REQUIRED.
        $this->selections->recordReprice($result);

        return response()->json($result->toContract());
    }
}
