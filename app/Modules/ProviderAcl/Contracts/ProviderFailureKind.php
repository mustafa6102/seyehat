<?php

namespace App\Modules\ProviderAcl\Contracts;

enum ProviderFailureKind: string
{
    case TIMEOUT = 'TIMEOUT';
    case RATE_LIMITED = 'RATE_LIMITED';
    case UNAVAILABLE = 'UNAVAILABLE';
    case CIRCUIT_OPEN = 'CIRCUIT_OPEN';
    case NOT_CONFIGURED = 'NOT_CONFIGURED';
    /** The provider integration cannot express these canonical criteria (no provider call was made). */
    case UNSUPPORTED_CRITERIA = 'UNSUPPORTED_CRITERIA';

    /** Machine code from contracts/registries/reason_error.yaml; null where the registry defines none. */
    public function reasonCode(): ?string
    {
        return match ($this) {
            self::TIMEOUT => 'PROVIDER_TIMEOUT',
            self::RATE_LIMITED => 'PROVIDER_RATE_LIMITED',
            default => null,
        };
    }

    public function messageTr(): string
    {
        return match ($this) {
            self::TIMEOUT => 'Sağlayıcı yanıtı zamanında alınamadı.',
            self::RATE_LIMITED => 'Sağlayıcı istek sınırı nedeniyle geçici olarak yanıt veremedi.',
            self::UNSUPPORTED_CRITERIA => 'Bu arama ölçütleri şu anda desteklenmiyor: gidiş-dönüş, esnek tarih, 9 kişiden fazla yolcu ve çocuk ya da bebek yolcu içeren aramalar yapılamaz.',
            default => 'Sağlayıcıya şu anda ulaşılamıyor.',
        };
    }

    /** 429 is not retried inside a call so a rate-limit storm is not amplified. */
    public function retryable(): bool
    {
        return $this === self::TIMEOUT || $this === self::UNAVAILABLE;
    }
}
