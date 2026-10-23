<?php

namespace App\Modules\Authorization\Application;

use App\Modules\Authorization\Contracts\AuthenticationRequired;
use App\Modules\Authorization\Contracts\BearerAuthenticator;
use App\Modules\Authorization\Contracts\PrincipalRef;

/**
 * v3.16 bearerAuth: "Authorization: Bearer <access token>" validated by OidcTokenVerifier against CFG-AUTH-OIDC-ISSUER and
 * CFG-AUTH-OIDC-AUDIENCE. Missing/invalid config fails closed. The token is never logged or persisted.
 */
final readonly class OidcBearerAuthenticator implements BearerAuthenticator
{
    public function __construct(private OidcTokenVerifier $verifier) {}

    public function authenticate(?string $authorizationHeader): PrincipalRef
    {
        $issuer = config('services.oidc.issuer');
        $audience = config('services.oidc.audience');
        if (! self::validIssuer($issuer) || ! is_string($audience) || trim($audience) === '') {
            throw new AuthenticationRequired;
        }
        if (! is_string($authorizationHeader) || preg_match('/^Bearer (\S+)$/', $authorizationHeader, $match) !== 1) {
            throw new AuthenticationRequired;
        }
        $claims = $this->verifier->verify($match[1], $issuer, $audience);

        return new PrincipalRef($issuer, $claims->sub);
    }

    /** CFG-AUTH-OIDC-ISSUER: an https URL of at most 255 characters (SCH-PrincipalRef.issuer). */
    public static function validIssuer(mixed $issuer): bool
    {
        return is_string($issuer) && str_starts_with($issuer, 'https://') && filter_var($issuer, FILTER_VALIDATE_URL) !== false
            && strlen($issuer) <= 255;
    }
}
