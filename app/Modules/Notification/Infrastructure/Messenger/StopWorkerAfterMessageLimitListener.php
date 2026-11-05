<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Messenger;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;

final class StopWorkerAfterMessageLimitListener implements EventSubscriberInterface
{
    private int $processedMessages = 0;

    private ?Worker $worker = null;

    private ?float $idleSince = null;

    public function __construct(
        private readonly int $messageLimit,
    ) {
        if ($messageLimit < 1) {
            throw new \InvalidArgumentException('Worker message limit must be at least 1.');
        }
    }

    public function setWorker(Worker $worker): void
    {
        $this->worker = $worker;
    }

    public function onMessageHandled(
        WorkerMessageHandledEvent $event,
    ): void {
        $this->increment();
    }

    public function onMessageFailed(
        WorkerMessageFailedEvent $event,
    ): void {
        $this->increment();
    }

    private function increment(): void
    {
        ++$this->processedMessages;

        if ($this->processedMessages >= $this->messageLimit) {
            $this->worker?->stop();
        }
    }

    public function getProcessedMessages(): int
    {
        return $this->processedMessages;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageHandledEvent::class => 'onMessageHandled',
            WorkerRunningEvent::class => 'onWorkerRunning',
            // Run after retry and failure transport listeners.
            WorkerMessageFailedEvent::class => ['onMessageFailed', -200],
        ];
    }

    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        if (!$event->isWorkerIdle()) {
            $this->idleSince = null;
            return;
        }

        $this->idleSince ??= microtime(true);

        if (microtime(true) - $this->idleSince >= 3) {
            $event->getWorker()->stop();
        }
    }
}