<?php

namespace App\Modules\FlightSearch\Infrastructure\Http;

use App\Modules\FlightSearch\Domain\FlightOfferRepository;
use Illuminate\Http\JsonResponse;

final readonly class FlightOfferController
{
    public function __construct(private FlightOfferRepository $offers) {}

    public function show(string $offerId): JsonResponse
    {
        $offer = $this->offers->find($offerId);

        if ($offer === null) {
            return response()->json([
                'error' => [
                    'code' => 'RESOURCE_NOT_FOUND',
                    'message_tr' => 'Uçuş teklifi bulunamadı.',
                    'correlation_id' => null,
                    'details' => null,
                ],
            ], 404);
        }

        return response()->json($offer->toContract());
    }
}
