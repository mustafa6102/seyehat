<?php

namespace App\Modules\Authorization\Application;

use App\Modules\Authorization\Contracts\AuthenticationRequired;
use App\Modules\Authorization\Infrastructure\Oidc\OidcSigningKeys;
use Firebase\JWT\JWT;
use stdClass;
use Throwable;

/**
 * Shared JWS/JWT validation of v3.16 x-token-validation, used by bearer API authentication (aud = CFG-AUTH-OIDC-AUDIENCE)
 * and by the browser login ID token (aud = client id): asymmetric signature with the issuer's JWKS (alg=none and HMAC
 * rejected), iss exactly the configured issuer, aud contains the expected audience, exp required and not passed, nbf when
 * present, non-empty sub. No leeway. Tokens and claims are never logged or persisted.
 */
final readonly class OidcTokenVerifier
{
    public function __construct(private OidcSigningKeys $keys) {}

    /** @throws AuthenticationRequired */
    public function verify(string $jwt, string $issuer, string $audience): stdClass
    {
        if (preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $jwt) !== 1) {
            throw new AuthenticationRequired;
        }
        try {
            $header = JWT::jsonDecode(JWT::urlsafeB64Decode(explode('.', $jwt)[0]));
        } catch (Throwable) {
            throw new AuthenticationRequired;
        }
        $alg = is_object($header) ? ($header->alg ?? null) : null;
        $kid = is_object($header) ? ($header->kid ?? null) : null;
        if (! in_array($alg, OidcSigningKeys::ALGORITHMS, true) || ! is_string($kid) || $kid === '') {
            throw new AuthenticationRequired;
        }

        try {
            $keys = $this->keys->forIssuer($issuer);
            if (! isset($keys[$kid])) {
                // Key rotation: one forced refresh of the issuer's JWKS.
                $keys = $this->keys->forIssuer($issuer, refresh: true);
            }
            $claims = JWT::decode($jwt, $keys);
        } catch (Throwable) {
            throw new AuthenticationRequired;
        }

        $tokenAudience = $claims->aud ?? null;
        $audiences = is_array($tokenAudience) ? $tokenAudience : [$tokenAudience];
        $subject = $claims->sub ?? null;
        if (($claims->iss ?? null) !== $issuer
            || ! in_array($audience, $audiences, true)
            || ! isset($claims->exp) || ! is_numeric($claims->exp)
            || ! is_string($subject) || $subject === '' || strlen($subject) > 255) {
            throw new AuthenticationRequired;
        }

        return $claims;
    }
}
