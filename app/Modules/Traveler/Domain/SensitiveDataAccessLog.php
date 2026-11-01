<?php

namespace App\Modules\Traveler\Domain;

use App\Modules\Authorization\Contracts\PrincipalRef;

/** RULE-PLT-PII-001-02: sensitive traveler data access is recorded without the field values. */
interface SensitiveDataAccessLog
{
    public function record(string $action, Traveler $traveler): void;

    /**
     * Encrypted profile access: field names only.
     *
     * @param  list<string>  $fields
     */
    public function recordFields(string $action, PrincipalRef $principal, string $tripRequestId, string $travelerId, array $fields, string $operationId): void;
}
