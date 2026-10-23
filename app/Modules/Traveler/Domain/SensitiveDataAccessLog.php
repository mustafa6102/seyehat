<?php

namespace App\Modules\Traveler\Domain;

/** RULE-PLT-PII-001-02: sensitive traveler data access is recorded without the field values. */
interface SensitiveDataAccessLog
{
    public function record(string $action, Traveler $traveler): void;
}
