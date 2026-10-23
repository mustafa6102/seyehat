<?php

namespace App\Modules\Authorization\Application;

use RuntimeException;

/** Browser login refusal with a Turkish user message only; provider responses, tokens and details are never included. */
final class OidcLoginFailed extends RuntimeException
{
    public static function unavailable(): self
    {
        return new self('Giriş şu anda kullanılamıyor: kimlik sağlayıcı yapılandırılmadı.');
    }

    public static function rejected(): self
    {
        return new self('Giriş tamamlanamadı. Lütfen yeniden deneyin.');
    }
}
