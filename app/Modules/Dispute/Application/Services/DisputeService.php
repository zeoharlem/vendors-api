<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Application\Services;

use App\Modules\Dispute\Application\Dtos\OpenDisputeDto;
use App\Modules\Dispute\Application\Dtos\RespondToDisputeDto;
use App\Modules\Dispute\Application\Dtos\UpdateDisputeStatusDto;
use App\Modules\Dispute\Domain\Contracts\DisputePayableManagerInterface;
use App\Modules\Dispute\Domain\Contracts\DisputeTransactionManagerInterface;
use App\Modules\Dispute\Domain\Contracts\DisputeVendorOrderAccessInterface;
use App\Modules\Dispute\Domain\Entities\Dispute;
use App\Modules\Dispute\Domain\Entities\DisputeHistory;
use App\Modules\Dispute\Domain\Enums\DisputeEventType;
use App\Modules\Dispute\Domain\Enums\DisputeStatus;
use App\Modules\Dispute\Domain\Exceptions\DisputeAlreadyExistsException;
use App\Modules\Dispute\Domain\Exceptions\DisputeNotFoundException;
use App\Modules\Dispute\Domain\Repositories\DisputeHistoryRepositoryInterface;
use App\Modules\Dispute\Domain\Repositories\DisputeRepositoryInterface;
use InvalidArgumentException;

final readonly class DisputeService
{
    public function __construct(
        private DisputeRepositoryInterface $disputeRepository,
        private DisputeHistoryRepositoryInterface $historyRepository,
        private DisputePayableManagerInterface $payableManager,
        private DisputeVendorOrderAccessInterface $vendorOrderAccess,
        private DisputeTransactionManagerInterface $transactionManager,
    ) {
    }


    public function open(
        OpenDisputeDto $dto,
        string $customerId
    ): Dispute {
        return $this->transactionManager->transactional(
            function () use ($dto, $customerId): Dispute {
                $vendorOrder = $this->vendorOrderAccess->getForCustomer(
                    $dto->vendorOrderId,
                    $customerId
                );

                if (
                    $this->disputeRepository->findActiveByVendorOrderForUpdate(
                        $vendorOrder->vendorOrderId
                    ) !== null
                ) {
                    throw DisputeAlreadyExistsException::forVendorOrder(
                        $vendorOrder->vendorOrderId
                    );
                }

                $disputeId = self::generateUuid();

                $previousVendorOrderStatus = $this->payableManager->holdForDispute(
                    $vendorOrder->vendorOrderId,
                    $disputeId
                );

                $dispute = new Dispute(
                    id: $disputeId,
                    orderId: $vendorOrder->orderId,
                    vendorOrderId: $vendorOrder->vendorOrderId,
                    vendorPayableId: $vendorOrder->vendorPayableId,
                    openedBy: $customerId,
                    reason: $dto->reason,
                    description: $dto->description,
                    status: DisputeStatus::OPEN,
                    previousVendorOrderStatus: $previousVendorOrderStatus,
                );

                $dispute = $this->disputeRepository->create($dispute);

                $this->historyRepository->append(
                    new DisputeHistory(
                        id: self::generateUuid(),
                        disputeId: $disputeId,
                        actorId: $customerId,
                        eventType: DisputeEventType::DISPUTE_OPENED,
                        previousStatus: null,
                        newStatus: DisputeStatus::OPEN,
                        note: 'Dispute opened.',
                        metadata: [
                            'vendor_order_id' => $vendorOrder->vendorOrderId,
                            'reason' => $dto->reason,
                        ],
                        createdAt: new \DateTimeImmutable(),
                    )
                );

                return $dispute;
            }
        );
    }


    public function findForCustomer(
        string $disputeId,
        string $customerId
    ): Dispute {
        return $this->disputeRepository->findByIdForCustomer(
            $disputeId,
            $customerId
        ) ?? throw DisputeNotFoundException::forId($disputeId);
    }

    public function findForStore(
        string $disputeId,
        string $storeId
    ): Dispute {
        return $this->disputeRepository->findByIdForStore(
            $disputeId,
            $storeId
        ) ?? throw DisputeNotFoundException::forId($disputeId);
    }

    public function respondByCustomer(
        string $disputeId,
        string $customerId,
        RespondToDisputeDto $dto
    ): Dispute {
        return $this->transactionManager->transactional(
            function () use ($disputeId, $customerId, $dto): Dispute {
                $dispute = $this->disputeRepository->findByIdForCustomer(
                    $disputeId,
                    $customerId
                );

                if ($dispute === null) {
                    throw DisputeNotFoundException::forId($disputeId);
                }

                if ($dispute->getStatus() !== DisputeStatus::AWAITING_CUSTOMER) {
                    throw new InvalidArgumentException(
                        'This dispute is not awaiting a customer response.'
                    );
                }

                $previousStatus = $dispute->getStatus();
                $dispute->transitionTo(DisputeStatus::UNDER_REVIEW);

                $this->disputeRepository->save($dispute);

                $this->historyRepository->append(
                    new DisputeHistory(
                        id: self::generateUuid(),
                        disputeId: $disputeId,
                        actorId: $customerId,
                        eventType: DisputeEventType::CUSTOMER_RESPONSE_SUBMITTED,
                        previousStatus: $previousStatus,
                        newStatus: DisputeStatus::UNDER_REVIEW,
                        note: $dto->message,
                        metadata: [],
                        createdAt: new \DateTimeImmutable(),
                    )
                );

                return $dispute;
            }
        );
    }

    public function respondByVendor(
        string $disputeId,
        string $storeId,
        string $vendorUserId,
        RespondToDisputeDto $dto
    ): Dispute {
        return $this->transactionManager->transactional(
            function () use ($disputeId, $storeId, $vendorUserId, $dto): Dispute {
                $dispute = $this->disputeRepository->findByIdForStore(
                    $disputeId,
                    $storeId
                );

                if ($dispute === null) {
                    throw DisputeNotFoundException::forId($disputeId);
                }

                if ($dispute->getStatus() !== DisputeStatus::AWAITING_VENDOR) {
                    throw new InvalidArgumentException(
                        'This dispute is not awaiting a vendor response.'
                    );
                }

                $previousStatus = $dispute->getStatus();
                $dispute->transitionTo(DisputeStatus::UNDER_REVIEW);

                $this->disputeRepository->save($dispute);

                $this->historyRepository->append(
                    new DisputeHistory(
                        id: self::generateUuid(),
                        disputeId: $disputeId,
                        actorId: $vendorUserId,
                        eventType: DisputeEventType::VENDOR_RESPONSE_SUBMITTED,
                        previousStatus: $previousStatus,
                        newStatus: DisputeStatus::UNDER_REVIEW,
                        note: $dto->message,
                        metadata: [
                            'store_id' => $storeId,
                        ],
                        createdAt: new \DateTimeImmutable(),
                    )
                );

                return $dispute;
            }
        );
    }

    public function updateStatus(
        string $disputeId,
        string $adminId,
        UpdateDisputeStatusDto $dto
    ): Dispute {
        return $this->transactionManager->transactional(
            function () use ($disputeId, $adminId, $dto): Dispute {
                $dispute = $this->disputeRepository->findByIdForUpdate(
                    $disputeId
                );

                if ($dispute === null) {
                    throw DisputeNotFoundException::forId($disputeId);
                }

                $previousStatus = $dispute->getStatus();

                $dispute->transitionTo($dto->status);

                $this->disputeRepository->save($dispute);

                $this->historyRepository->append(
                    new DisputeHistory(
                        id: self::generateUuid(),
                        disputeId: $disputeId,
                        actorId: $adminId,
                        eventType: DisputeEventType::STATUS_CHANGED,
                        previousStatus: $previousStatus,
                        newStatus: $dto->status,
                        note: $dto->note,
                        metadata: [],
                        createdAt: new \DateTimeImmutable(),
                    )
                );

                return $dispute;
            }
        );
    }

    private static function generateUuid(): string
    {
        $bytes = random_bytes(16);

        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
