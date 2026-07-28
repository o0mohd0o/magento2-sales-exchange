<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\Settlement;

use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Magento\Sales\Api\Data\InvoiceCommentCreationInterface;
use Magento\Sales\Api\Data\InvoiceCommentCreationInterfaceFactory;
use Magento\Sales\Api\Data\InvoiceItemCreationInterface;
use Magento\Sales\Api\Data\InvoiceItemCreationInterfaceFactory;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;

/**
 * Build an explicit full-invoice service-contract request.
 */
class InvoiceRequestBuilder
{
    private InvoiceItemCreationInterfaceFactory $itemFactory;

    private InvoiceCommentCreationInterfaceFactory $commentFactory;

    private DecimalMath $quantityMath;

    public function __construct(
        InvoiceItemCreationInterfaceFactory $itemFactory,
        InvoiceCommentCreationInterfaceFactory $commentFactory,
        DecimalMath $quantityMath
    ) {
        $this->itemFactory = $itemFactory;
        $this->commentFactory = $commentFactory;
        $this->quantityMath = $quantityMath;
    }

    /**
     * @return InvoiceItemCreationInterface[]
     */
    public function buildItems(OrderInterface $order): array
    {
        $items = [];
        foreach ($this->getOrderItems($order) as $orderItem) {
            $orderItemId = (int)$orderItem->getItemId();
            $quantity = $this->orderedQuantity($orderItem);
            if ($orderItemId <= 0 || isset($items[$orderItemId])) {
                throw new InvariantViolationException(
                    __('The replacement order contains an invalid or duplicate item.')
                );
            }
            foreach ([
                $orderItem->getQtyInvoiced(),
                $orderItem->getQtyCanceled(),
                $orderItem->getQtyRefunded(),
            ] as $usedQuantity) {
                if ($this->quantityMath->compare(
                    $this->component($usedQuantity),
                    '0'
                ) !== 0) {
                    throw new InvariantViolationException(
                        __('The replacement order is no longer available for one exact full invoice.')
                    );
                }
            }
            /** @var InvoiceItemCreationInterface $item */
            $item = $this->itemFactory->create();
            $item->setOrderItemId($orderItemId)
                ->setQty((float)$quantity);
            $items[$orderItemId] = $item;
        }
        if ($items === []) {
            throw new InvariantViolationException(
                __('The replacement invoice requires at least one positive item.')
            );
        }
        ksort($items, SORT_NUMERIC);

        return array_values($items);
    }

    /**
     * Canonical full-order quantities, valid both before and after invoicing.
     *
     * @return array<int, string>
     */
    public function quantities(OrderInterface $order): array
    {
        $quantities = [];
        foreach ($this->getOrderItems($order) as $orderItem) {
            $orderItemId = (int)$orderItem->getItemId();
            if ($orderItemId <= 0 || isset($quantities[$orderItemId])) {
                throw new InvariantViolationException(
                    __('The replacement order contains an invalid or duplicate item.')
                );
            }
            $quantities[$orderItemId] = $this->orderedQuantity($orderItem);
        }
        if ($quantities === []) {
            throw new InvariantViolationException(
                __('The replacement order has no invoiceable item quantities.')
            );
        }
        ksort($quantities, SORT_NUMERIC);

        return $quantities;
    }

    public function buildComment(
        string $exchangeIncrementId,
        string $operationKey
    ): InvoiceCommentCreationInterface {
        /** @var InvoiceCommentCreationInterface $comment */
        $comment = $this->commentFactory->create();
        $comment->setComment(
            (string)__(
                'Created by exchange %1 (%2).',
                $exchangeIncrementId,
                $operationKey
            )
        )->setIsVisibleOnFront(0);

        return $comment;
    }

    /**
     * @return OrderItemInterface[]
     */
    private function getOrderItems(OrderInterface $order): array
    {
        $items = $order->getItems();
        if (!is_array($items)) {
            throw new InvariantViolationException(
                __('The replacement order item collection is unavailable.')
            );
        }

        return $items;
    }

    private function orderedQuantity(OrderItemInterface $item): string
    {
        $quantity = $this->quantityMath->assertNonNegative(
            $this->component($item->getQtyOrdered()),
            'Replacement order item quantity'
        );
        if ($this->quantityMath->compare($quantity, '0') <= 0) {
            throw new InvariantViolationException(
                __('Replacement invoice item quantities must be positive.')
            );
        }

        return $quantity;
    }

    /**
     * @param mixed $value
     */
    private function component($value): string
    {
        return $value === null || $value === '' ? '0' : (string)$value;
    }
}
