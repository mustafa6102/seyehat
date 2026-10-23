<?php

namespace App\Shared\Outbox\Infrastructure;

use App\Shared\Outbox\Contracts\OutboxEvent;
use App\Shared\Outbox\Contracts\OutboxWriter;
use Illuminate\Database\ConnectionInterface;

final readonly class DatabaseOutboxWriter implements OutboxWriter
{
    public function __construct(private ConnectionInterface $database) {}

    public function append(OutboxEvent $event): void
    {
        $this->database->table('outbox_events')->insert([
            'trip_id' => $event->tripId,
            'revision_id' => $event->revisionId,
            'sequence' => $event->sequence,
            'correlation_id' => $event->correlationId,
        ]);
    }
}
