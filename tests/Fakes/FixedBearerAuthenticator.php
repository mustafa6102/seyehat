<?php

namespace Tests\Fakes;

use App\Modules\Authorization\Contracts\AuthenticationRequired;
use App\Modules\Authorization\Contracts\BearerAuthenticator;
use App\Modules\Authorization\Contracts\PrincipalRef;

/** Test-only authenticator for API tests that are not about token validation: accepts exactly "Bearer test-token". */
final class FixedBearerAuthenticator implements BearerAuthenticator
{
    public const TOKEN = 'test-token';

    public function __construct(public PrincipalRef $principal = new PrincipalRef('https://issuer.test', 'api-user')) {}

    public function authenticate(?string $authorizationHeader): PrincipalRef
    {
        if ($authorizationHeader !== 'Bearer '.self::TOKEN) {
            throw new AuthenticationRequired;
        }

        return $this->principal;
    }
}
