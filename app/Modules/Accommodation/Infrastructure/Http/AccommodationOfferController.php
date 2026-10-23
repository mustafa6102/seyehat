<?php

namespace App\Modules\Accommodation\Infrastructure\Http;

use App\Modules\Accommodation\Application\AccommodationOfferService;
use App\Modules\Accommodation\Application\AccommodationSelectionService;
use Illuminate\Http\JsonResponse;

/** OP-getAccommodationOffer and OP-revalidateAccommodationOffer (v3.14). offerId is only an identifier. */
final readonly class AccommodationOfferController
{
    public function __construct(private AccommodationOfferService $offers, private AccommodationSelectionService $selections) {}

    public function show(string $offerId): JsonResponse
    {
        $offer = $this->offers->find($offerId);

        return $offer === null
            ? $this->notFound('Konaklama teklifi bulunamadı veya süresi doldu.')
            : response()->json($offer);
    }

    public function revalidate(string $offerId): JsonResponse
    {
        $result = $this->offers->revalidate($offerId);
        if ($result === null) {
            return $this->notFound('Yeniden doğrulanacak konaklama teklifi bulunamadı veya süresi doldu.');
        }
        // TRN-ACCSEL-02: a CHANGED result moves a CURRENT selection of this offer to RECONFIRMATION_REQUIRED.
        $this->selections->recordRevalidation($result);

        return response()->json($result);
    }

    private function notFound(string $messageTr): JsonResponse
    {
        return response()->json(['error' => ['code' => 'RESOURCE_NOT_FOUND', 'message_tr' => $messageTr, 'correlation_id' => null, 'details' => null]], 404);
    }
}
