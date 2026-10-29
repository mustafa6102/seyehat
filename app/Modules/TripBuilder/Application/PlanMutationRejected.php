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

    /** RC-VALIDATION-DAY-FRAME-UNAVAILABLE (v3.18, RULE-DAY-001-04) */
    public static function dayFrameUnavailable(): self
    {
        return new self('DAY_FRAME_UNAVAILABLE', 'Bu seyahat talebinin tarihlerinden plan günleri oluşturulamıyor; yer bir güne atanamaz.', 422);
    }

    /** RC-VALIDATION-DAY-ASSIGNMENT-INVALID (v3.18, RULE-DAY-001-05) */
    public static function dayAssignmentInvalid(): self
    {
        return new self('DAY_ASSIGNMENT_INVALID', 'Seçilen gün seyahat tarihleri içinde değil veya sıra geçersiz.', 422);
    }

    /** RC-CONFLICT-IDEMPOTENCY-KEY */
    public static function idempotencyConflict(): self
    {
        return new self('IDEMPOTENCY_KEY_CONFLICT', 'Aynı idempotency anahtarı farklı bir istek için kullanılamaz.', 409);
    }
}
