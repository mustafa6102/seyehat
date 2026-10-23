<?php

namespace App\Modules\Authorization\Infrastructure\Oidc;

use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory;
use RuntimeException;
use Throwable;

/**
 * Signing keys of the configured issuer (CFG-AUTH-OIDC-ISSUER) from its OpenID Connect Discovery document (jwks_uri).
 * Only asymmetric signature keys are kept. Keys are cached only for the JWKS response's own Cache-Control max-age; no
 * other lifetime is invented. Any failure yields an exception (fail closed); responses and keys are never logged.
 */
final readonly class OidcSigningKeys
{
    /** Asymmetric JWS algorithms accepted by v3.16 x-token-validation (alg=none and HMAC are never accepted). */
    public const ALGORITHMS = ['RS256', 'RS384', 'RS512', 'PS256', 'ES256', 'ES384'];

    /** RFC 7518 curve -> algorithm for EC keys published without "alg". */
    private const EC_ALGORITHMS = ['P-256' => 'ES256', 'P-384' => 'ES384'];

    public function __construct(private Factory $http, private Cache $cache) {}

    /**
     * @return array<string, Key> keyed by kid
     *
     * @throws RuntimeException when no usable key can be obtained
     */
    public function forIssuer(string $issuer, bool $refresh = false): array
    {
        $cacheKey = 'oidc:jwks:'.hash('sha256', $issuer);
        $jwks = $refresh ? null : $this->cache->get($cacheKey);
        if (! is_array($jwks)) {
            [$jwks, $maxAge] = $this->fetch($issuer);
            if ($maxAge > 0) {
                $this->cache->put($cacheKey, $jwks, $maxAge);
            } else {
                $this->cache->forget($cacheKey);
            }
        }

        try {
            $keys = JWK::parseKeySet(['keys' => $jwks]);
        } catch (Throwable) {
            throw new RuntimeException('OIDC signing keys are unusable.');
        }
        if ($keys === []) {
            throw new RuntimeException('OIDC signing keys are unusable.');
        }

        return $keys;
    }

    /**
     * The issuer's OpenID Connect Discovery document. Its issuer must equal the configured issuer exactly (Discovery 4.3);
     * jwks_uri and, when present, authorization_endpoint / token_endpoint must be https URLs.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    public function discovery(string $issuer): array
    {
        try {
            $response = $this->http->acceptJson()->get(rtrim($issuer, '/').'/.well-known/openid-configuration');
            $document = $response->successful() ? $response->json() : null;
        } catch (Throwable) {
            throw new RuntimeException('OIDC discovery failed.');
        }
        if (! is_array($document) || ($document['issuer'] ?? null) !== $issuer || ! self::httpsUrl($document['jwks_uri'] ?? null)) {
            throw new RuntimeException('OIDC discovery document is unusable.');
        }
        foreach (['authorization_endpoint', 'token_endpoint'] as $endpoint) {
            if (array_key_exists($endpoint, $document) && ! self::httpsUrl($document[$endpoint])) {
                throw new RuntimeException('OIDC discovery document is unusable.');
            }
        }

        return $document;
    }

    public static function httpsUrl(mixed $url): bool
    {
        return is_string($url) && str_starts_with($url, 'https://') && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /** @return array{0: list<array<string, mixed>>, 1: int} usable JWKs and the JWKS max-age in seconds (0 = do not cache) */
    private function fetch(string $issuer): array
    {
        $jwksUri = $this->discovery($issuer)['jwks_uri'];
        try {
            $response = $this->http->acceptJson()->get($jwksUri);
            $set = $response->successful() ? $response->json('keys') : null;
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new RuntimeException('OIDC discovery failed.');
        }
        if (! is_array($set)) {
            throw new RuntimeException('OIDC JWKS is unusable.');
        }

        $usable = [];
        foreach ($set as $jwk) {
            $jwk = is_array($jwk) ? $this->signingKey($jwk) : null;
            if ($jwk !== null) {
                $usable[] = $jwk;
            }
        }
        if ($usable === []) {
            throw new RuntimeException('OIDC JWKS has no usable signing key.');
        }
        $cacheControl = (string) $response->header('Cache-Control');
        preg_match('/(?:^|,)\s*max-age=(\d+)/i', $cacheControl, $match);
        $noStore = preg_match('/no-store|no-cache/i', $cacheControl) === 1;

        return [$usable, $noStore ? 0 : (int) ($match[1] ?? 0)];
    }

    /**
     * @param  array<string, mixed>  $jwk
     * @return array<string, mixed>|null the key with an explicit asymmetric "alg", or null when it is not a usable signing key
     */
    private function signingKey(array $jwk): ?array
    {
        $kid = $jwk['kid'] ?? null;
        $use = $jwk['use'] ?? 'sig';
        if (! is_string($kid) || $kid === '' || $use !== 'sig') {
            return null;
        }
        $kty = $jwk['kty'] ?? null;
        $alg = $jwk['alg'] ?? match ($kty) {
            'RSA' => 'RS256',
            'EC' => self::EC_ALGORITHMS[$jwk['crv'] ?? ''] ?? null,
            default => null,
        };
        $family = is_string($alg) ? substr($alg, 0, 2) : '';
        if (! in_array($alg, self::ALGORITHMS, true)
            || ! (($kty === 'RSA' && in_array($family, ['RS', 'PS'], true)) || ($kty === 'EC' && $family === 'ES'))) {
            return null;
        }

        return ['alg' => $alg] + $jwk;
    }
}
