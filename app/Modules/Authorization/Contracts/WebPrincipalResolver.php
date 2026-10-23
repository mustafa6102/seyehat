<?php

namespace App\Modules\Authorization\Contracts;

use Illuminate\Http\Request;

/**
 * Principal of a server-rendered web request. The browser OIDC login (Authorization Code + PKCE, ADR-010) is not integrated
 * yet, so the production resolver returns null and web flows that need an owner cannot proceed (no ownerless records).
 */
interface WebPrincipalResolver
{
    public function resolve(Request $request): ?PrincipalRef;
}
