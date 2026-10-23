<?php

namespace App\Shared\Outbox\Contracts;

/**
 * SCH-OutboxEvent v3.2 (PROVISIONAL: ADR-008).
 */
final readonly class OutboxEvent
{
    public function __construct(
        public string $tripId,
        public string $revisionId,
        public ?int $sequence = null,
        public ?string $correlationId = null,
    ) {}
}
