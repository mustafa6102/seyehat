<?php

namespace App\Modules\Authorization\Infrastructure\Web;

use App\Modules\Authorization\Application\OidcBearerAuthenticator;
use App\Modules\Authorization\Contracts\PrincipalRef;
use App\Modules\Authorization\Contracts\WebPrincipalResolver;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Web-channel principal: the (issuer, subject) stored in the server-side session only after a completed browser OIDC login
 * (OidcBrowserLogin). Nothing from the query, form, cookies or unsigned token content is used. A stored principal of an
 * issuer other than the configured one is ignored. This never authenticates API routes (they keep bearer tokens).
 */
final readonly class SessionWebPrincipalResolver implements WebPrincipalResolver
{
    public const SESSION_KEY = 'auth.principal';

    public const PENDING_LOGIN_KEY = 'auth.oidc_pending';

    public function resolve(Request $request): ?PrincipalRef
    {
        if (! $request->hasSession()) {
            return null;
        }
        $stored = $request->session()->get(self::SESSION_KEY);
        $issuer = config('services.oidc.issuer');
        if (! is_array($stored) || ! OidcBearerAuthenticator::validIssuer($issuer) || ($stored['issuer'] ?? null) !== $issuer
            || ! is_string($stored['subject'] ?? null)) {
            return null;
        }
        try {
            return new PrincipalRef($stored['issuer'], $stored['subject']);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
