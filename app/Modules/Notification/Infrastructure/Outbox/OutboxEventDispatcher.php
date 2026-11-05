<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Outbox;

use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;
use RuntimeException;
use Throwable;

final readonly class OutboxEventDispatcher
{
    private const MAX_ATTEMPTS = 10;

    public function __construct(
        private AdapterInterface $db,
        private OutboxEventHandlerRegistry $handlerRegistry,
    ) {
    }

    /**
     * Dispatches eligible outbox events.
     *
     * @return array{
     *     claimed: int,
     *     processed: int,
     *     retry_scheduled: int,
     *     permanently_failed: int
     * }
     */
    public function dispatchPending(int $limit = 25): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new RuntimeException(
                'Outbox dispatch limit must be between 1 and 100.',
            );
        }

        $events = $this->claimEvents($limit);

        $result = [
            'claimed' => count($events),
            'processed' => 0,
            'retry_scheduled' => 0,
            'permanently_failed' => 0,
        ];

        foreach ($events as $event) {
            try {
                $this->dispatchEvent($event);

                $this->markProcessed($event);

                $result['processed']++;
            } catch (Throwable $exception) {
                try {
                    $permanentlyFailed = $this->scheduleRetry(
                        $event,
                        $exception,
                    );

                    if ($permanentlyFailed) {
                        $result['permanently_failed']++;
                    } else {
                        $result['retry_scheduled']++;
                    }
                } catch (Throwable $retryException) {
                    throw new RuntimeException(
                        sprintf(
                            'Failed to dispatch outbox event %s. Original error: %s. Retry scheduling error: %s.',
                            (string) $event['id'],
                            $exception->getMessage(),
                            $retryException->getMessage(),
                        ),
                        0,
                        $retryException,
                    );
                }
            }
        }

        return $result;
    }

    /**
     * Claims events atomically, allowing multiple dispatchers to run safely.
     *
     * @return array<int, array<string, mixed>>
     */
    private function claimEvents(int $limit): array
    {
        $this->db->begin();

        try {
            // Permanently fail events that have exhausted their attempts
            // and are eligible for another processing attempt.
            $this->db->query(
                <<<'SQL'
                UPDATE public.outbox_events
                SET
                    status = 'failed',
                    locked_at = NULL,
                    last_error = COALESCE(
                        last_error,
                        'Maximum outbox dispatch attempts exceeded.'
                    )
                WHERE attempts >= :max_attempts
                  AND (
                      (
                          status IN ('pending', 'failed')
                          AND available_at <= NOW()
                      )
                      OR (
                          status = 'processing'
                          AND locked_at <= NOW() - INTERVAL '5 minutes'
                      )
                  )
                SQL,
                [
                    'max_attempts' => self::MAX_ATTEMPTS,
                ],
            );

            $events = $this->db->query(
                <<<'SQL'
                WITH claimable_events AS (
                    SELECT id
                    FROM public.outbox_events
                    WHERE attempts < :max_attempts
                      AND (
                          (
                              status IN ('pending', 'failed')
                              AND available_at <= NOW()
                          )
                          OR (
                              status = 'processing'
                              AND locked_at <= NOW() - INTERVAL '5 minutes'
                          )
                      )
                    ORDER BY available_at ASC, created_at ASC
                    LIMIT :limit
                    FOR UPDATE SKIP LOCKED
                )
                UPDATE public.outbox_events AS event
                SET
                    status = 'processing',
                    attempts = event.attempts + 1,
                    locked_at = NOW(),
                    last_error = NULL
                FROM claimable_events
                WHERE event.id = claimable_events.id
                RETURNING
                    event.id,
                    event.event_type,
                    event.aggregate_type,
                    event.aggregate_id,
                    event.payload,
                    event.attempts,
                    event.locked_at
                SQL,
                [
                    'max_attempts' => self::MAX_ATTEMPTS,
                    'limit' => $limit,
                ],
            )->fetchAll();

            $this->db->commit();

            return $events;
        } catch (Throwable $exception) {
            try {
                $this->db->rollback();
            } catch (Throwable) {
                // Preserve the original exception.
            }

            throw $exception;
        }
    }

    /**
     * Routes a claimed event to its registered application handler.
     *
     * @param array<string, mixed> $event
     */
    private function dispatchEvent(array $event): void
    {
        $eventType = (string) ($event['event_type'] ?? '');

        $handler = $this->handlerRegistry->getHandler($eventType);

        $handler->handle($event);
    }

    /**
     * Marks a successfully dispatched event as processed.
     *
     * @param array<string, mixed> $event
     */
    private function markProcessed(array $event): void
    {
        $result = $this->db->query(
            <<<'SQL'
            UPDATE public.outbox_events
            SET
                status = 'processed',
                processed_at = NOW(),
                locked_at = NULL,
                last_error = NULL
            WHERE id = :id
              AND status = 'processing'
              AND locked_at = CAST(:locked_at AS timestamptz)
            RETURNING id
            SQL,
            [
                'id' => $event['id'],
                'locked_at' => $event['locked_at'],
            ],
        );

        $updatedEvent = $result->fetch();

        if ($updatedEvent === false || $updatedEvent === null) {
            throw new RuntimeException(
                sprintf(
                    'Could not mark outbox event %s as processed. The dispatcher may have lost ownership.',
                    (string) $event['id'],
                ),
            );
        }
    }

    /**
     * Schedules a retry or permanently fails an event.
     *
     * @param array<string, mixed> $event
     */
    private function scheduleRetry(
        array $event,
        Throwable $exception,
    ): bool {
        $attempts = (int) $event['attempts'];

        $permanentlyFailed = $attempts >= self::MAX_ATTEMPTS;

        // 30, 60, 120, 240... seconds, capped at one hour.
        $delaySeconds = min(
            3600,
            30 * (2 ** min(max($attempts - 1, 0), 7)),
        );

        $error = sprintf(
            '%s: %s',
                $exception::class,
            $exception->getMessage(),
        );

        $result = $this->db->query(
            <<<'SQL'
            UPDATE public.outbox_events
            SET
                status = ?,
                available_at = CASE
                    WHEN ? = 'pending'
                    THEN NOW() + (? * INTERVAL '1 second')
                    ELSE available_at
                END,
                locked_at = NULL,
                last_error = ?
            WHERE id = ?
              AND status = 'processing'
              AND locked_at = CAST(? AS timestamptz)
            RETURNING id
            SQL,
            [
                $permanentlyFailed ? 'failed' : 'pending',
                $permanentlyFailed ? 'failed' : 'pending',
                $delaySeconds,
                substr($error, 0, 4000),
                $event['id'],
                $event['locked_at'],
            ],
        );

        $updatedEvent = $result->fetch();

        if ($updatedEvent === false || $updatedEvent === null) {
            throw new RuntimeException(
                sprintf(
                    'Could not schedule retry for outbox event %s. The dispatcher may have lost ownership.',
                    (string) $event['id'],
                ),
            );
        }

        return $permanentlyFailed;
    }

    /**
     * Permanently fails an event that cannot be handled.
     *
     * @param array<string, mixed> $event
     */
    private function markPermanentlyFailed(array $event, string $reason, ): void
    {
        $result = $this->db->query(
            <<<'SQL'
                UPDATE public.outbox_events
                SET
                    status = 'failed',
                    locked_at = NULL,
                    last_error = ?
                WHERE id = ?
                AND status = 'processing'
                AND locked_at = CAST(? AS timestamptz)
                RETURNING id
            SQL,
            [
                mb_substr($reason, 0, 4000),
                (string) $event['id'],
                (string) $event['locked_at'],
            ],
        );

        if ($result->fetch() === false) {
            throw new RuntimeException(
                sprintf(
                    'Could not permanently fail outbox event "%s": processing lock was lost.',
                    (string) $event['id'],
                ),
            );
        }
    }
}
