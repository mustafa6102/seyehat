<?php

namespace App\Modules\Opportunity\Infrastructure\Http;

use App\Modules\Api\Contracts\OwnershipDecision;
use App\Modules\Api\Contracts\TripRequestOwnership;
use App\Modules\Authorization\Contracts\PrincipalRef;
use App\Modules\FlightSearch\Contracts\FlightSelectionReader;
use App\Modules\FlightSearch\Domain\Money;
use App\Modules\Opportunity\Application\SelectedFlightOpportunity;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class FlightOpportunityController
{
    public function __construct(private TripRequestOwnership $ownership, private FlightSelectionReader $selections, private SelectedFlightOpportunity $evaluator) {}

    public function show(Request $request, string $tripRequestId): JsonResponse
    {
        $principal = $request->attributes->get(PrincipalRef::class);
        if (! $principal instanceof PrincipalRef) {
            return $this->error('AUTHENTICATION_REQUIRED', 'Bu işlem için kimlik doğrulaması gerekiyor.', 401);
        }
        $access = $this->ownership->check($principal, $tripRequestId);
        if ($access !== OwnershipDecision::ALLOWED) {
            return $access === OwnershipDecision::NOT_FOUND
                ? $this->error('RESOURCE_NOT_FOUND', 'Seyahat talebi bulunamadı.', 404)
                : $this->error('AUTHORIZATION_DENIED', 'Bu işlem için yetkiniz bulunmuyor.', 403);
        }
        $input = $request->query();
        $amount = $input['max_minor_units'] ?? null;
        $currency = $input['currency'] ?? null;
        if (array_diff(array_keys($input), ['max_minor_units', 'currency']) !== []
            || ! is_string($amount) || preg_match('/^(0|[1-9][0-9]*)$/D', $amount) !== 1
            || strlen($amount) > strlen((string) PHP_INT_MAX)
            || strlen($amount) === strlen((string) PHP_INT_MAX) && strcmp($amount, (string) PHP_INT_MAX) > 0
            || ! is_string($currency) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            return $this->error('SCHEMA_VALIDATION_FAILED', 'Fiyat eşiği ve para birimini kontrol edin.', 400);
        }
        $selection = $this->selections->find($tripRequestId);
        if ($selection === null) {
            return $this->error('RESOURCE_NOT_FOUND', 'Bu seyahat talebi için uçuş seçimi bulunamadı.', 404);
        }

        return response()->json($this->evaluator->evaluate($selection, new Money((int) $amount, $currency), new DateTimeImmutable('now')))
            ->header('Cache-Control', 'private, no-store');
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message_tr' => $message, 'correlation_id' => null, 'details' => null]], $status)
            ->header('Cache-Control', 'private, no-store');
    }
}
