<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\Workflow;

use Bonlineco\SalesExchange\Api\ConditionCode;
use Bonlineco\SalesExchange\Api\Data\ExchangeInterface;
use Bonlineco\SalesExchange\Api\Data\ReturnItemInterface;
use Bonlineco\SalesExchange\Api\DispositionCode;
use Bonlineco\SalesExchange\Api\FinancialRowCalculatorInterface;
use Bonlineco\SalesExchange\Api\History\ActorType;
use Bonlineco\SalesExchange\Api\Status\ExchangeStatus;
use Bonlineco\SalesExchange\Api\Status\ReturnStatus;
use Bonlineco\SalesExchange\Api\Status\StateDimension;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\HistoryFactory;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Bonlineco\SalesExchange\Model\ResourceModel\Exchange as ExchangeResource;
use Bonlineco\SalesExchange\Model\ResourceModel\History as HistoryResource;
use Bonlineco\SalesExchange\Model\ResourceModel\ReturnItem as ReturnItemResource;
use Bonlineco\SalesExchange\Model\ReturnItemFactory;
use Bonlineco\SalesExchange\Model\ReturnItemQuantityValidator;
use Bonlineco\SalesExchange\Model\VersionGuard;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Atomically record one warehouse line mutation and advance the case version.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class WarehouseRecorder
{
    private ExchangeResource $exchangeResource;

    private ReturnItemResource $returnItemResource;

    private ReturnItemFactory $returnItemFactory;

    private HistoryFactory $historyFactory;

    private HistoryResource $historyResource;

    private VersionGuard $versionGuard;

    private ReturnItemQuantityValidator $quantityValidator;

    private FinancialRowCalculatorInterface $financialRowCalculator;

    private DecimalMath $quantityMath;

    public function __construct(
        ExchangeResource $exchangeResource,
        ReturnItemResource $returnItemResource,
        ReturnItemFactory $returnItemFactory,
        HistoryFactory $historyFactory,
        HistoryResource $historyResource,
        VersionGuard $versionGuard,
        ReturnItemQuantityValidator $quantityValidator,
        FinancialRowCalculatorInterface $financialRowCalculator,
        DecimalMath $quantityMath
    ) {
        $this->exchangeResource = $exchangeResource;
        $this->returnItemResource = $returnItemResource;
        $this->returnItemFactory = $returnItemFactory;
        $this->historyFactory = $historyFactory;
        $this->historyResource = $historyResource;
        $this->versionGuard = $versionGuard;
        $this->quantityValidator = $quantityValidator;
        $this->financialRowCalculator = $financialRowCalculator;
        $this->quantityMath = $quantityMath;
    }

    public function recordReceipt(
        int $exchangeId,
        int $expectedVersion,
        int $itemId,
        int $expectedItemVersion,
        string $receivedQuantity,
        int $actorId,
        ?string $comment
    ): int {
        return $this->record(
            $exchangeId,
            $expectedVersion,
            $itemId,
            $expectedItemVersion,
            $actorId,
            $comment,
            function (ReturnItemInterface $item, array $persisted) use ($receivedQuantity): void {
                unset($persisted);
                $item->setReceivedQty($this->quantityMath->normalize($receivedQuantity))
                    ->setReceiptResolved(true);
                $this->validateQuantities($item);
            },
            'receipt_recorded',
            [ReturnStatus::AUTHORIZED, ReturnStatus::IN_TRANSIT]
        );
    }

    public function recordInspection(
        int $exchangeId,
        int $expectedVersion,
        int $itemId,
        int $expectedItemVersion,
        string $acceptedQuantity,
        string $rejectedQuantity,
        string $conditionCode,
        string $disposition,
        int $actorId,
        ?string $comment
    ): int {
        if (!in_array($conditionCode, ConditionCode::all(), true)
            || !in_array($disposition, DispositionCode::all(), true)
        ) {
            throw new InvariantViolationException(
                __('Select a supported condition and disposition.')
            );
        }

        return $this->record(
            $exchangeId,
            $expectedVersion,
            $itemId,
            $expectedItemVersion,
            $actorId,
            $comment,
            function (ReturnItemInterface $item, array $persisted) use (
                $acceptedQuantity,
                $rejectedQuantity,
                $conditionCode,
                $disposition
            ): void {
                unset($persisted);
                $item->setAcceptedQty($this->quantityMath->normalize($acceptedQuantity))
                    ->setRejectedQty($this->quantityMath->normalize($rejectedQuantity))
                    ->setConditionCode($conditionCode)
                    ->setDisposition($disposition)
                    ->setRowCreditAmount(
                        $this->financialRowCalculator->execute(
                            $item->getAcceptedQty(),
                            $item->getUnitCreditAmount()
                        )
                    );
                $this->validateQuantities($item);
                if ($this->quantityMath->compare(
                    $this->quantityMath->add(
                        $item->getAcceptedQty(),
                        $item->getRejectedQty()
                    ),
                    '0'
                ) <= 0) {
                    throw new InvariantViolationException(
                        __('Record at least one accepted or rejected unit.')
                    );
                }
            },
            'inspection_recorded',
            [ReturnStatus::RECEIVED]
        );
    }

    /**
     * @param callable(ReturnItemInterface, array<string, mixed>): void $mutator
     * @param string[] $allowedReturnStatuses
     */
    private function record(
        int $exchangeId,
        int $expectedVersion,
        int $itemId,
        int $expectedItemVersion,
        int $actorId,
        ?string $comment,
        callable $mutator,
        string $action,
        array $allowedReturnStatuses
    ): int {
        if ($exchangeId <= 0 || $itemId <= 0 || $actorId <= 0) {
            throw new InvariantViolationException(
                __('A valid exchange, return item, and admin actor are required.')
            );
        }
        $connection = $this->exchangeResource->getConnection();
        $connection->beginTransaction();
        try {
            $exchangeRow = $this->exchangeResource->getDataForUpdate($exchangeId);
            if ($exchangeRow === null) {
                throw new NoSuchEntityException(
                    __('No exchange case exists for ID "%1".', $exchangeId)
                );
            }
            if ((string)$exchangeRow[ExchangeInterface::EXCHANGE_STATUS]
                !== ExchangeStatus::IN_PROGRESS
                || !in_array(
                    (string)$exchangeRow[ExchangeInterface::RETURN_STATUS],
                    $allowedReturnStatuses,
                    true
                )
            ) {
                throw new InvariantViolationException(
                    __('The return is not in the correct warehouse phase for this action.')
                );
            }
            $nextVersion = $this->versionGuard->assertCurrentAndIncrement(
                $expectedVersion,
                (int)$exchangeRow[ExchangeInterface::VERSION],
                'exchange case'
            );
            $itemRow = $this->returnItemResource->getDataForUpdate($itemId);
            if ($itemRow === null
                || (int)$itemRow[ReturnItemInterface::EXCHANGE_ID] !== $exchangeId
            ) {
                throw new NoSuchEntityException(
                    __('The selected return item does not belong to this exchange.')
                );
            }
            $nextItemVersion = $this->versionGuard->assertCurrentAndIncrement(
                $expectedItemVersion,
                (int)$itemRow[ReturnItemInterface::VERSION],
                'return item'
            );
            $item = $this->returnItemFactory->create();
            $item->setData($itemRow);
            $before = $this->quantitySnapshot($item);
            $mutator($item, $itemRow);
            $item->setVersion($nextItemVersion);
            $this->returnItemResource->save($item);
            if (!$this->exchangeResource->updateVersion(
                $exchangeId,
                $expectedVersion,
                $nextVersion
            )) {
                throw new InvariantViolationException(
                    __('The exchange changed while the warehouse record was being saved.')
                );
            }
            $this->recordHistory(
                $exchangeId,
                $action,
                $before,
                $this->quantitySnapshot($item),
                $actorId,
                $comment
            );
            $connection->commit();

            return $nextVersion;
        } catch (\Throwable $exception) {
            $connection->rollBack();
            if ($exception instanceof LocalizedException) {
                throw $exception;
            }
            throw new CouldNotSaveException(
                __('The warehouse quantity could not be recorded.'),
                $exception
            );
        }
    }

    private function validateQuantities(ReturnItemInterface $item): void
    {
        $this->quantityValidator->execute(
            $item->getRequestedQty(),
            $item->getAllocatedQty(),
            $item->getReceivedQty(),
            $item->getAcceptedQty(),
            $item->getRejectedQty(),
            $item->getCreditedQty()
        );
    }

    private function quantitySnapshot(ReturnItemInterface $item): string
    {
        return sprintf(
            'received=%s;receipt_resolved=%d;accepted=%s;rejected=%s;credited=%s',
            $item->getReceivedQty(),
            $item->isReceiptResolved() ? 1 : 0,
            $item->getAcceptedQty(),
            $item->getRejectedQty(),
            $item->getCreditedQty()
        );
    }

    private function recordHistory(
        int $exchangeId,
        string $action,
        string $from,
        string $to,
        int $actorId,
        ?string $comment
    ): void {
        $history = $this->historyFactory->create();
        $history->setExchangeId($exchangeId)
            ->setAction($action)
            ->setStatusDimension(StateDimension::RETURN)
            ->setFromValue($from)
            ->setToValue($to)
            ->setActorType(ActorType::ADMIN)
            ->setActorId($actorId)
            ->setComment($comment);
        $this->historyResource->save($history);
    }
}
