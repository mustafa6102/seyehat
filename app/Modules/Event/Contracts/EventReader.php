<?php

namespace App\Modules\Event\Contracts;

interface EventReader
{
    /** @return array<string, mixed>|null SCH-Event with event_id */
    public function find(string $eventId): ?array;
}
