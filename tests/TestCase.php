<?php

namespace Tests;

use App\Modules\Authorization\Contracts\BearerAuthenticator;
use App\Modules\Authorization\Contracts\WebPrincipalResolver;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Fakes\FixedBearerAuthenticator;
use Tests\Fakes\FixedWebPrincipalResolver;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Test fixtures for flows that are not about authentication: a fixed web principal (production resolves it from the
        // browser OIDC login session) and a bearer authenticator accepting only FixedBearerAuthenticator::TOKEN. The
        // authentication tests rebind the real OidcBearerAuthenticator and SessionWebPrincipalResolver.
        $this->app->instance(WebPrincipalResolver::class, new FixedWebPrincipalResolver);
        $this->app->instance(BearerAuthenticator::class, new FixedBearerAuthenticator);
    }
}
