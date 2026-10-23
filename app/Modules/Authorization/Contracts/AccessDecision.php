<?php

namespace App\Modules\Authorization\Contracts;

final readonly class AccessDecision
{
    public const MATRIX_ALLOW = 'MATRIX_ALLOW';

    public const POLICY_SATISFIED = 'POLICY_SATISFIED';

    public const MATRIX_DENY = 'MATRIX_DENY';

    public const SCOPE_MISSING = 'SCOPE_MISSING';

    public const NOT_SELF = 'NOT_SELF';

    public const TRAVELER_NOT_IN_TRIP = 'TRAVELER_NOT_IN_TRIP';

    public const SYSTEM_WORKFLOW_REQUIRED = 'SYSTEM_WORKFLOW_REQUIRED';

    public const DECISION_OPEN = 'DECISION_OPEN';

    public const SSOT_CONFLICT = 'SSOT_CONFLICT';

    public const UNKNOWN_PERMISSION = 'UNKNOWN_PERMISSION';

    /** @param string $policy matrix cell applied: ALLOW, DENY or POLICY-* */
    public function __construct(
        public bool $allowed,
        public string $policy,
        public string $reason,
        public ?string $decisionRef = null,
    ) {}
}
