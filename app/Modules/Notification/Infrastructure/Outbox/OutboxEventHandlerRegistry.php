<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Outbox;

use App\Modules\Notification\Domain\Contracts\OutboxEventHandlerInterface;
use RuntimeException;

final readonly class OutboxEventHandlerRegistry
{
    /**
     * @var array<string, OutboxEventHandlerInterface>
     */
    private array $handlers;

    /**
     * @param array<string, OutboxEventHandlerInterface> $handlers
     */
    public function __construct(array $handlers)
    {
        foreach ($handlers as $eventType => $handler) {
            if (
                !is_string($eventType)
                || trim($eventType) === ''
                || !$handler instanceof OutboxEventHandlerInterface
            ) {
                throw new RuntimeException(
                    'Invalid outbox event handler registration.',
                );
            }
        }

        $this->handlers = $handlers;
    }

    public function getHandler(
        string $eventType,
    ): OutboxEventHandlerInterface {
        $handler = $this->handlers[$eventType] ?? null;

        if ($handler === null) {
            throw new RuntimeException(
                sprintf(
                    'No handler registered for outbox event type "%s".',
                    $eventType,
                ),
            );
        }

        return $handler;
    }

    public function supports(string $eventType): bool
    {
        return isset($this->handlers[$eventType]);
    }

    /**
     * @return list<string>
     */
    public function supportedEventTypes(): array
    {
        return array_keys($this->handlers);
    }
}