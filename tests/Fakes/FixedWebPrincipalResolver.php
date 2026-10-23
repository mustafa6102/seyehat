<?php

namespace Tests\Fakes;

use App\Modules\Authorization\Contracts\PrincipalRef;
use App\Modules\Authorization\Contracts\WebPrincipalResolver;
use Illuminate\Http\Request;

/** Test-only web principal; production binds SessionWebPrincipalResolver (browser OIDC login). */
final class FixedWebPrincipalResolver implements WebPrincipalResolver
{
    public function __construct(public ?PrincipalRef $principal = new PrincipalRef('https://issuer.test', 'web-user')) {}

    public function resolve(Request $request): ?PrincipalRef
    {
        return $this->principal;
    }
}
