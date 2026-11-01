<?php

namespace App\Modules\Traveler\Contracts;

use RuntimeException;

/** A refused Basic Traveler operation: machine code, Turkish message and HTTP status only, never a request value. */
final class BasicTravelerRejected extends RuntimeException
{
    private function __construct(public readonly string $machineCode, string $messageTr, public readonly int $httpStatus)
    {
        parent::__construct($messageTr);
    }

    public static function notFound(string $messageTr = 'Seyahat bulunamadı.'): self
    {
        return new self('RESOURCE_NOT_FOUND', $messageTr, 404);
    }

    public static function invalidRequest(): self
    {
        return new self('SCHEMA_VALIDATION_FAILED', 'İstek sözleşme doğrulamasından geçemedi.', 400);
    }

    public static function tripNotInitialized(): self
    {
        return new self('TRIP_NOT_INITIALIZED', 'Yolcu eklemek için önce plana en az bir öğe ekleyin.', 409);
    }

    public static function capacityUnavailable(): self
    {
        return new self('TRAVELER_CAPACITY_UNAVAILABLE', 'Bu seyahatin yetişkin yolcu kapasitesi dolu veya bilinmiyor.', 409);
    }

    public static function idempotencyConflict(): self
    {
        return new self('IDEMPOTENCY_KEY_CONFLICT', 'Aynı idempotency anahtarı farklı bir istek için kullanılamaz.', 409);
    }
}
