<?php

namespace App\Modules\Authorization\Domain;

use App\Modules\Authorization\Contracts\AccessDecision;
use App\Modules\Authorization\Contracts\AccessRequest;

interface AccessAuditLog
{
    public function record(AccessRequest $request, AccessDecision $decision): void;
}
