<?php

namespace App\Modules\Authorization\Application;

use App\Modules\Authorization\Contracts\AuthenticationRequired;
use App\Modules\Authorization\Contracts\PrincipalRef;
use App\Modules\Authorization\Infrastructure\Oidc\OidcSigningKeys;
use Illuminate\Http\Client\Factory;
use Throwable;

/**
 * Browser login (ADR-010, v3.16 x-token-validation.clients): OpenID Connect Authorization Code flow with PKCE (S256), random
 * state and nonce, against the single configured issuer. The principal is the validated ID token's (iss, sub); the ID and
 * access tokens are only used inside complete() and are never stored or logged. No implicit or password grant exists.
 */
final readonly class OidcBrowserLogin
{
    public function __construct(private OidcSigningKeys $keys, private OidcTokenVerifier $verifier, private Factory $http) {}

    /**
     * @return array{url: string, pending: array{state: string, nonce: string, verifier: string, return_to: string}}
     *
     * @throws OidcLoginFailed
     */
    public function begin(string $returnTo): array
    {
        [$issuer, $clientId, $redirectUri] = $this->settings();
        $endpoint = $this->endpoint($issuer, 'authorization_endpoint');
        $pending = [
            'state' => self::random(32),
            'nonce' => self::random(32),
            'verifier' => self::random(48),
            'return_to' => $returnTo,
        ];
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => 'openid',
            'state' => $pending['state'],
            'nonce' => $pending['nonce'],
            'code_challenge' => self::base64Url(hash('sha256', $pending['verifier'], true)),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        return ['url' => $endpoint.(str_contains($endpoint, '?') ? '&' : '?').$query, 'pending' => $pending];
    }

    /**
     * @param  array<string, mixed>|null  $pending  the single-use server-side login state (already removed from the session)
     *
     * @throws OidcLoginFailed
     */
    public function complete(?array $pending, mixed $state, mixed $code, mixed $error): PrincipalRef
    {
        [$issuer, $clientId, $redirectUri] = $this->settings();
        // State is checked before any token processing; a missing, reused or mismatched state is refused.
        if (! is_array($pending) || ! is_string($pending['state'] ?? null) || ! is_string($state) || $error !== null
            || ! hash_equals($pending['state'], $state)
            || ! is_string($code) || $code === '' || strlen($code) > 2048) {
            throw OidcLoginFailed::rejected();
        }
        $tokenEndpoint = $this->endpoint($issuer, 'token_endpoint');
        $form = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $clientId,
            'code_verifier' => (string) ($pending['verifier'] ?? ''),
        ];
        $secret = config('services.oidc.client_secret');
        if (is_string($secret) && $secret !== '') {
            // Confidential client deployments only (client_secret_post); a public PKCE client sends no secret.
            $form['client_secret'] = $secret;
        }
        try {
            $response = $this->http->acceptJson()->asForm()->post($tokenEndpoint, $form);
            $idToken = $response->successful() ? $response->json('id_token') : null;
        } catch (Throwable) {
            throw OidcLoginFailed::rejected();
        }
        if (! is_string($idToken)) {
            throw OidcLoginFailed::rejected();
        }

        try {
            // OIDC Core 3.1.3.7: ID token aud contains the client id; azp must be the client id when several audiences exist.
            $claims = $this->verifier->verify($idToken, $issuer, $clientId);
        } catch (AuthenticationRequired) {
            throw OidcLoginFailed::rejected();
        }
        $audiences = is_array($claims->aud) ? $claims->aud : [$claims->aud];
        $nonce = $claims->nonce ?? null;
        if (! is_string($nonce) || ! hash_equals((string) ($pending['nonce'] ?? ''), $nonce)
            || (count($audiences) > 1 && ($claims->azp ?? null) !== $clientId)) {
            throw OidcLoginFailed::rejected();
        }

        // Only the validated issuer and subject identify the principal; e-mail, name and other claims are ignored.
        return new PrincipalRef($issuer, $claims->sub);
    }

    /**
     * CFG-AUTH-OIDC-ISSUER plus the browser client id and exact redirect URI. Missing or invalid values fail closed.
     *
     * @return array{0: string, 1: string, 2: string}
     *
     * @throws OidcLoginFailed
     */
    private function settings(): array
    {
        $issuer = config('services.oidc.issuer');
        $clientId = config('services.oidc.client_id');
        $redirectUri = config('services.oidc.redirect_uri');
        $redirectOk = is_string($redirectUri) && filter_var($redirectUri, FILTER_VALIDATE_URL) !== false
            && (str_starts_with($redirectUri, 'https://') || (! app()->isProduction() && str_starts_with($redirectUri, 'http://')))
            && parse_url($redirectUri, PHP_URL_PATH) === route('auth.callback', [], false);
        if (! OidcBearerAuthenticator::validIssuer($issuer) || ! is_string($clientId) || trim($clientId) === '' || ! $redirectOk) {
            throw OidcLoginFailed::unavailable();
        }

        return [$issuer, $clientId, $redirectUri];
    }

    /** @throws OidcLoginFailed */
    private function endpoint(string $issuer, string $name): string
    {
        try {
            $endpoint = $this->keys->discovery($issuer)[$name] ?? null;
        } catch (Throwable) {
            throw OidcLoginFailed::unavailable();
        }
        if (! OidcSigningKeys::httpsUrl($endpoint)) {
            throw OidcLoginFailed::unavailable();
        }

        return $endpoint;
    }

    private static function random(int $bytes): string
    {
        return self::base64Url(random_bytes($bytes));
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
