<?php

namespace App\Modules\Authorization\Contracts;

/** Public AuthZ service (PLT-AUTH-001). Every decision is written to the access audit log. */
interface AccessControl
{
    public function authorize(AccessRequest $request): AccessDecision;

    /** @throws AccessDenied */
    public function ensure(AccessRequest $request): void;
}
