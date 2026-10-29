<?php

namespace App\Modules\HowTo\Application;

use RuntimeException;

final class HowGuideRejected extends RuntimeException
{
    public function __construct(
        public readonly string $machineCode,
        string $message,
        public readonly int $httpStatus,
    ) {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self('RESOURCE_NOT_FOUND', 'Plan veya plan öğesi bulunamadı.', 404);
    }

    public static function stale(): self
    {
        return new self('REVISION_STALE', 'Plan bu sırada değişti. Güncel planı yeniden yükleyin.', 409);
    }

    public static function unsupported(): self
    {
        return new self('HOW_CONTEXT_UNSUPPORTED', 'Bu plan öğesi için adım adım rehber henüz desteklenmiyor.', 422);
    }

    public static function validation(string $message): self
    {
        return new self('VALIDATION_ERROR', $message, 400);
    }

    public static function idempotencyConflict(): self
    {
        return new self('IDEMPOTENCY_KEY_CONFLICT', 'Aynı idempotency anahtarı farklı bir istek için kullanılamaz.', 409);
    }

    public static function idempotencyInProgress(): self
    {
        return new self('IDEMPOTENCY_IN_PROGRESS', 'Aynı yenileme isteği halen işleniyor. Lütfen kısa süre sonra yeniden deneyin.', 409);
    }
}
