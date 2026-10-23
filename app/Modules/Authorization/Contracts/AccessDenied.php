<?php

namespace App\Modules\Authorization\Contracts;

use RuntimeException;

/** RC-AUTHZ-DENIED; carries only the registry Turkish message. */
final class AccessDenied extends RuntimeException
{
    public const MACHINE_CODE = 'AUTHORIZATION_DENIED';

    public function __construct(public readonly AccessDecision $decision)
    {
        parent::__construct('Bu işlem için yetkiniz bulunmuyor.');
    }
}
