<?php

namespace App\Modules\Poi\Infrastructure\Http;

use App\Modules\Poi\Application\PoiService;
use Illuminate\Http\JsonResponse;

/** OP-getPoi and OP-verifyPoi (v3.15). poiId is only an identifier; facts come from the server-side canonical store. */
final readonly class PoiController
{
    public function __construct(private PoiService $pois) {}

    public function show(string $poiId): JsonResponse
    {
        $poi = $this->pois->get($poiId);

        return $poi === null ? $this->notFound() : response()->json($poi);
    }

    public function verify(string $poiId): JsonResponse
    {
        $result = $this->pois->verify($poiId);

        return $result === null ? $this->notFound() : response()->json($result);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['error' => ['code' => 'RESOURCE_NOT_FOUND', 'message_tr' => 'Gezilecek yer bulunamadı.', 'correlation_id' => null, 'details' => null]], 404);
    }
}
