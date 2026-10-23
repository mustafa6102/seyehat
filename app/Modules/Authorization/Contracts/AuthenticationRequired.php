<?php

namespace App\Modules\Authorization\Contracts;

use RuntimeException;

/** RC-AUTHN-REQUIRED (401). Carries only the registry Turkish message; validation details are never exposed. */
final class AuthenticationRequired extends RuntimeException
{
    public const MACHINE_CODE = 'AUTHENTICATION_REQUIRED';

    public function __construct()
    {
        parent::__construct('Bu işlem için kimlik doğrulaması gerekiyor.');
    }
}
