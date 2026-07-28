<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model;

use Bonlineco\SalesExchange\Api\Data\ReturnItemInterface;
use Bonlineco\SalesExchange\Api\Status\ReturnStatus;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;

/**
 * Protect return quantities as each aggregate workflow phase closes.
 */
class ReturnItemLifecycleValidator
{
    private DecimalMath $moneyMath;

    private DecimalMath $quantityMath;

    public function __construct(DecimalMath $moneyMath, DecimalMath $quantityMath)
    {
        $this->moneyMath = $moneyMath;
        $this->quantityMath = $quantityMath;
    }

    /**
     * @param array<string, mixed>|null $persisted
     * @throws InvariantViolationException
     */
    public function execute(
        ReturnItemInterface $returnItem,
        ?array $persisted,
        string $returnStatus
    ): void {
        if ($persisted === null && $returnStatus !== ReturnStatus::PENDING) {
            throw new InvariantViolationException(
                __('Return items can only be added while the return is pending.')
            );
        }
        if ($persisted !== null) {
            if ((string)$persisted[ReturnItemInterface::SKU] !== $returnItem->getSku()
                || (string)$persisted[ReturnItemInterface::NAME] !== $returnItem->getName()
            ) {
                throw new InvariantViolationException(
                    __('Original order item snapshots are immutable after creation.')
                );
            }
            $this->assertMoneyUnchanged(
                $persisted,
                ReturnItemInterface::UNIT_CREDIT_AMOUNT,
                $returnItem->getUnitCreditAmount()
            );
        }

        if ($returnStatus === ReturnStatus::PENDING) {
            if ($returnItem->isReceiptResolved()) {
                throw new InvariantViolationException(
                    __('A warehouse receipt cannot be resolved before return authorization.')
                );
            }
            $this->assertZero(
                [
                    $returnItem->getReceivedQty(),
                    $returnItem->getAcceptedQty(),
                    $returnItem->getRejectedQty(),
                    $returnItem->getCreditedQty(),
                ],
                __('Inspection quantities cannot be recorded before return authorization.')
            );
            return;
        }

        if ($persisted === null) {
            return;
        }

        $this->assertQuantityUnchanged(
            $persisted,
            ReturnItemInterface::CREDITED_QTY,
            $returnItem->getCreditedQty()
        );
        $this->assertQuantityUnchanged(
            $persisted,
            ReturnItemInterface::REQUESTED_QTY,
            $returnItem->getRequestedQty()
        );
        $this->assertQuantityUnchanged(
            $persisted,
            ReturnItemInterface::ALLOCATED_QTY,
            $returnItem->getAllocatedQty()
        );
        if (in_array($returnStatus, [ReturnStatus::AUTHORIZED, ReturnStatus::IN_TRANSIT], true)) {
            if ((bool)$persisted[ReturnItemInterface::RECEIPT_RESOLVED]
                && !$returnItem->isReceiptResolved()
            ) {
                throw new InvariantViolationException(
                    __('A resolved warehouse receipt cannot be reopened.')
                );
            }
            $this->assertZero(
                [$returnItem->getAcceptedQty(), $returnItem->getRejectedQty()],
                __('Inspection outcomes cannot be recorded before receipt.')
            );
            return;
        }

        $this->assertQuantityUnchanged(
            $persisted,
            ReturnItemInterface::RECEIVED_QTY,
            $returnItem->getReceivedQty()
        );
        if ((bool)$persisted[ReturnItemInterface::RECEIPT_RESOLVED]
            !== $returnItem->isReceiptResolved()
        ) {
            throw new InvariantViolationException(
                __('The finalized warehouse receipt state is immutable.')
            );
        }
        if ($returnStatus === ReturnStatus::RECEIVED) {
            return;
        }

        $this->assertQuantityUnchanged(
            $persisted,
            ReturnItemInterface::ACCEPTED_QTY,
            $returnItem->getAcceptedQty()
        );
        $this->assertQuantityUnchanged(
            $persisted,
            ReturnItemInterface::REJECTED_QTY,
            $returnItem->getRejectedQty()
        );
        $this->assertMoneyUnchanged(
            $persisted,
            ReturnItemInterface::ROW_CREDIT_AMOUNT,
            $returnItem->getRowCreditAmount()
        );
    }

    /**
     * @param string[] $values
     */
    private function assertZero(array $values, \Magento\Framework\Phrase $message): void
    {
        foreach ($values as $value) {
            if ($this->quantityMath->compare($value, '0') !== 0) {
                throw new InvariantViolationException($message);
            }
        }
    }

    /**
     * @param array<string, mixed> $persisted
     */
    private function assertQuantityUnchanged(array $persisted, string $field, string $incoming): void
    {
        if ($this->quantityMath->compare((string)($persisted[$field] ?? '0'), $incoming) !== 0) {
            throw new InvariantViolationException(
                __('Authorized or completed return quantities are immutable.')
            );
        }
    }

    /**
     * @param array<string, mixed> $persisted
     */
    private function assertMoneyUnchanged(array $persisted, string $field, string $incoming): void
    {
        if ($this->moneyMath->compare((string)$persisted[$field], $incoming) !== 0) {
            throw new InvariantViolationException(
                __('The authorized return financial snapshot is immutable.')
            );
        }
    }
}
