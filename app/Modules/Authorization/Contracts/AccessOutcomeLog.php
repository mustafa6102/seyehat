<?php

namespace App\Modules\Authorization\Contracts;

/**
 * Completes an audited decision once its result is committed. A decision is taken before a created record exists, so the
 * shared operation id is what joins the decision to the real record id.
 */
interface AccessOutcomeLog
{
    public const CREATED = 'CREATED';

    public const REPLAYED = 'REPLAYED';

    public function recordTravelerCreate(string $operationId, string $subjectId, string $tripRequestId, string $travelerId, string $outcome): void;

    public function recordSubjectRequest(string $requestId, string $subjectId, string $tripRequestId, string $travelerId, string $type, string $status): void;
}
