<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\Creditmemo;

use Bonlineco\SalesExchange\Api\Data\ReturnItemInterface;
use Bonlineco\SalesExchange\Api\DispositionCode;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Bonlineco\SalesExchange\Model\ReturnableOrderItemValidator;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Build accepted-but-uncredited quantities from authoritative order data.
 */
class Planner
{
    private ReturnableOrderItemValidator $returnableOrderItemValidator;

    private DecimalMath $quantityMath;

    public function __construct(
        ReturnableOrderItemValidator $returnableOrderItemValidator,
        DecimalMath $quantityMath
    ) {
        $this->returnableOrderItemValidator = $returnableOrderItemValidator;
        $this->quantityMath = $quantityMath;
    }

    /**
     * @param array<int, array<string, mixed>> $returnRows
     */
    public function execute(OrderInterface $order, array $returnRows): Plan
    {
        $orderItems = [];
        foreach ($order->getItems() as $orderItem) {
            $orderItems[(int)$orderItem->getItemId()] = $orderItem;
        }
        $quantities = [];
        $updates = [];
        $returnToStock = [];
        foreach ($returnRows as $row) {
            $accepted = $this->quantityMath->assertNonNegative(
                (string)($row[ReturnItemInterface::ACCEPTED_QTY] ?? ''),
                'Accepted quantity'
            );
            $credited = $this->quantityMath->assertNonNegative(
                (string)($row[ReturnItemInterface::CREDITED_QTY] ?? '0'),
                'Credited quantity'
            );
            if ($this->quantityMath->compare($credited, $accepted) > 0) {
                throw new InvariantViolationException(
                    __('Credited quantity cannot exceed accepted quantity.')
                );
            }
            $quantity = $this->quantityMath->subtract($accepted, $credited);
            if ($this->quantityMath->compare($quantity, '0') === 0) {
                continue;
            }

            $returnItemId = (int)($row[ReturnItemInterface::ENTITY_ID] ?? 0);
            $orderItemId = (int)($row[ReturnItemInterface::ORDER_ITEM_ID] ?? 0);
            if ($returnItemId <= 0 || $orderItemId <= 0) {
                throw new InvariantViolationException(
                    __('A persisted accepted return line is invalid.')
                );
            }
            if (!isset($orderItems[$orderItemId])) {
                throw new InvariantViolationException(
                    __('The accepted original order item no longer exists.')
                );
            }
            $orderItem = $orderItems[$orderItemId];
            if ((int)$orderItem->getOrderId() !== (int)$order->getEntityId()) {
                throw new InvariantViolationException(
                    __('An accepted return item does not belong to the original order.')
                );
            }
            $this->returnableOrderItemValidator->execute($orderItem);

            $invoiced = $this->quantityMath->assertNonNegative(
                (string)$orderItem->getQtyInvoiced(),
                'Invoiced quantity'
            );
            $refunded = $this->quantityMath->assertNonNegative(
                (string)$orderItem->getQtyRefunded(),
                'Refunded quantity'
            );
            if ($this->quantityMath->compare($refunded, $invoiced) > 0) {
                throw new InvariantViolationException(
                    __('Refunded quantity cannot exceed invoiced quantity.')
                );
            }
            if ($this->quantityMath->compare($refunded, $credited) < 0) {
                throw new InvariantViolationException(
                    __('Native refunded quantity is behind the exchange credit handoff.')
                );
            }
            $available = $this->quantityMath->subtract($invoiced, $refunded);
            if ($this->quantityMath->compare($quantity, $available) > 0) {
                throw new InvariantViolationException(
                    __(
                        'Accepted quantity for "%1" is not fully invoiced and unrefunded. '
                        . 'Create the native invoice first or resolve another refund before retrying.',
                        (string)$orderItem->getName()
                    )
                );
            }

            $disposition = (string)($row[ReturnItemInterface::DISPOSITION] ?? '');
            if (!in_array($disposition, DispositionCode::all(), true)) {
                throw new InvariantViolationException(
                    __('Every credited return line requires a finalized inventory disposition.')
                );
            }
            $quantities[$orderItemId] = $quantity;
            $updates[$returnItemId] = [
                'quantity' => $quantity,
                'credited_qty' => $credited,
            ];
            if ($disposition === DispositionCode::RESTOCK) {
                // The visible configurable parent is intentional. Magento creates
                // its child CM row and both legacy inventory and MSI propagate it.
                $returnToStock[] = $orderItemId;
            }
        }

        if ($quantities === []) {
            throw new InvariantViolationException(
                __('Every accepted return quantity already has a linked native credit memo.')
            );
        }

        sort($returnToStock, SORT_NUMERIC);

        return new Plan($quantities, $updates, array_values(array_unique($returnToStock)));
    }
}
