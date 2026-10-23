<?php

namespace App\Shared\Outbox\Contracts;

interface OutboxWriter
{
    public function append(OutboxEvent $event): void;
}
