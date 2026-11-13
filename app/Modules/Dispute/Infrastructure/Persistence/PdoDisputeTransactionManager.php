<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Infrastructure\Persistence;

use App\Modules\Dispute\Domain\Contracts\DisputeTransactionManagerInterface;
use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;
use Throwable;

final readonly class PdoDisputeTransactionManager implements DisputeTransactionManagerInterface
{
    public function __construct(
        private AdapterInterface $db
    ) {
    }

    public function transactional(callable $operation): mixed
    {
        $this->db->begin();

        try {
            $result = $operation();

            $this->db->commit();

            return $result;
        } catch (Throwable $exception) {
            try {
                $this->db->rollback();
            } catch (Throwable) {
                // Preserve the original exception.
            }

            throw $exception;
        }
    }
}
