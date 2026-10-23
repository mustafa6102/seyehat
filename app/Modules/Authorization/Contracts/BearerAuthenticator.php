<?php

namespace App\Modules\Authorization\Contracts;

/** v3.16 bearerAuth: validates an "Authorization: Bearer" OIDC access token (x-token-validation). */
interface BearerAuthenticator
{
    /** @throws AuthenticationRequired for a missing/invalid token or missing/invalid auth configuration */
    public function authenticate(?string $authorizationHeader): PrincipalRef;
}
