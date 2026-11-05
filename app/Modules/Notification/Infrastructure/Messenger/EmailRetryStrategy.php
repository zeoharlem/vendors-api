<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Messenger;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Retry\RetryStrategyInterface;

final class EmailRetryStrategy implements RetryStrategyInterface
{
    private readonly MultiplierRetryStrategy $strategy;

    public function __construct()
    {
        $this->strategy = new MultiplierRetryStrategy(
            maxRetries: 3,
            delayMilliseconds: 60_000,
            multiplier: 2.0,
            maxDelayMilliseconds: 300_000,
            jitter: 0.1,
        );
    }

    public function isRetryable(
        Envelope $message,
        ?\Throwable $throwable = null,
    ): bool {
        return $this->strategy->isRetryable($message, $throwable);
    }

    public function getWaitingTime(
        Envelope $message,
        ?\Throwable $throwable = null,
    ): int {
        return $this->strategy->getWaitingTime($message, $throwable);
    }
}