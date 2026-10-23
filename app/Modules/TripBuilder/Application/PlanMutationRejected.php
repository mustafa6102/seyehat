<?php

namespace App\Modules\TripBuilder\Application;

use RuntimeException;

/** A refused plan operation, carrying the existing registry machine code, Turkish message and HTTP status only. */
final class PlanMutationRejected extends RuntimeException
{
    private function __construct(public readonly string $machineCode, string $messageTr, public readonly int $httpStatus)
    {
        parent::__construct($messageTr);
    }

    /** RC-RESOURCE-NOT-FOUND */
    public static function notFound(string $messageTr = 'İstenen kayıt bulunamadı.'): self
    {
        return new self('RESOURCE_NOT_FOUND', $messageTr, 404);
    }

    /** RC-CONFLICT-REVISION-STALE (PLT-CONC-001, OD-027) */
    public static function staleRevision(): self
    {
        return new self('REVISION_STALE', 'Plan sürümü güncel değil.', 409);
    }

    /** RC-CONFLICT-IDEMPOTENCY-KEY */
    public static function idempotencyConflict(): self
    {
        return new self('IDEMPOTENCY_KEY_CONFLICT', 'Aynı idempotency anahtarı farklı bir istek için kullanılamaz.', 409);
    }
}
