<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Domain\Contracts;

interface DisputeTransactionManagerInterface
{
    /**
     * Executes an operation inside a database transaction.
     *
     * Commits when the callback succeeds and rolls back when
     * the callback throws an exception.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function transactional(callable $operation): mixed;
}
