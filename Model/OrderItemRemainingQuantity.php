<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model;

use Bonlineco\SalesExchange\Api\RemainingQuantityCalculatorInterface;
use Bonlineco\SalesExchange\Model\ResourceModel\ReturnItem as ReturnItemResource;
use Magento\Sales\Api\Data\OrderItemInterface;

/**
 * Authoritative remaining exchange quantity for one original-order line.
 */
class OrderItemRemainingQuantity
{
    private ReturnItemResource $returnItemResource;

    private RemainingQuantityCalculatorInterface $remainingQuantityCalculator;

    private CanonicalRefundedQuantity $canonicalRefundedQuantity;

    public function __construct(
        ReturnItemResource $returnItemResource,
        RemainingQuantityCalculatorInterface $remainingQuantityCalculator,
        CanonicalRefundedQuantity $canonicalRefundedQuantity
    ) {
        $this->returnItemResource = $returnItemResource;
        $this->remainingQuantityCalculator = $remainingQuantityCalculator;
        $this->canonicalRefundedQuantity = $canonicalRefundedQuantity;
    }

    /**
     * @param array<int, OrderItemInterface> $orderItems
     */
    public function execute(
        OrderItemInterface $orderItem,
        array $orderItems
    ): string {
        return $this->remainingQuantityCalculator->execute(
            (string)$orderItem->getQtyInvoiced(),
            $this->canonicalRefundedQuantity->execute(
                $orderItem,
                $orderItems
            ),
            $this->returnItemResource->getAllocatedQuantity((int)$orderItem->getItemId())
        );
    }
}
