<?php

namespace App\Modules\Authorization\Contracts;

use RuntimeException;

/** No acceptable audit pseudonym key: the operation is refused rather than audited without a subject. */
final class AuditIdentityUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Denetim kimliği anahtarı yapılandırılmamış.');
    }
}
