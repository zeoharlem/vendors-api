<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain\Contracts;

interface OutboxEventHandlerInterface
{
    /**
     * Handles a claimed outbox event.
     *
     * @param array<string, mixed> $event
     */
    public function handle(array $event): void;
}