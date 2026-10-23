<?php

namespace App\Http\Controllers;

use App\Modules\Authorization\Application\OidcBrowserLogin;
use App\Modules\Authorization\Application\OidcLoginFailed;
use App\Modules\Authorization\Infrastructure\Web\SessionWebPrincipalResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Browser OIDC login/logout (ADR-010, Authorization Code + PKCE). Only the validated (issuer, subject) is kept in the
 * server-side session; tokens are never stored. The return path is an application-relative path only (no open redirect).
 */
final readonly class OidcLoginController
{
    public function __construct(private OidcBrowserLogin $login) {}

    public function start(Request $request): RedirectResponse
    {
        try {
            $login = $this->login->begin(self::safePath($request->query('donus')));
        } catch (OidcLoginFailed $failure) {
            return $this->failed($failure);
        }
        // Single-use login state; a new login replaces an unfinished one.
        $request->session()->put(SessionWebPrincipalResolver::PENDING_LOGIN_KEY, $login['pending']);

        return redirect()->away($login['url']);
    }

    public function callback(Request $request): RedirectResponse
    {
        // Pulled before validation, so a callback (or its state) can never be replayed.
        $pending = $request->session()->pull(SessionWebPrincipalResolver::PENDING_LOGIN_KEY);
        try {
            $principal = $this->login->complete(
                is_array($pending) ? $pending : null,
                $request->query('state'),
                $request->query('code'),
                $request->query('error'),
            );
        } catch (OidcLoginFailed $failure) {
            return $this->failed($failure);
        }
        $request->session()->regenerate(true);
        $request->session()->put(SessionWebPrincipalResolver::SESSION_KEY, ['issuer' => $principal->issuer, 'subject' => $principal->subject]);

        return redirect(self::safePath($pending['return_to'] ?? null));
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget(SessionWebPrincipalResolver::SESSION_KEY);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('trip-planner.index');
    }

    /** An application-relative path ("/..."); anything else (absolute, scheme-relative, backslash, control chars) is "/". */
    public static function safePath(mixed $path): string
    {
        if (! is_string($path) || strlen($path) > 2048 || preg_match('#^/(?![/\\\\])[^\s\\\\]*$#', $path) !== 1
            || parse_url($path, PHP_URL_SCHEME) !== null || parse_url($path, PHP_URL_HOST) !== null) {
            return '/';
        }

        return $path;
    }

    private function failed(OidcLoginFailed $failure): RedirectResponse
    {
        return redirect()->route('trip-planner.index')->with('auth_error', $failure->getMessage());
    }
}
