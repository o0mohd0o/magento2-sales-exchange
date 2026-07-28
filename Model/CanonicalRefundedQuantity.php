<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model;

use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Magento\Sales\Api\Data\OrderItemInterface;

/**
 * Resolve native refunded quantity for one canonical returnable order line.
 */
class CanonicalRefundedQuantity
{
    private DecimalMath $quantityMath;

    public function __construct(DecimalMath $quantityMath)
    {
        $this->quantityMath = $quantityMath;
    }

    /**
     * Standard configurable credit memos update both visible parent and dummy
     * child, while a crafted child-only memo can update only the child. The
     * greater of the parent counter and all mapped child counters counts the
     * supported paired shape once and accounts for pre-existing one-sided
     * history. New one-sided documents are rejected by ReservationGuard.
     *
     * @param array<int, OrderItemInterface> $orderItems
     */
    public function execute(
        OrderItemInterface $canonicalItem,
        array $orderItems
    ): string {
        $parentRefunded = $this->quantityMath->assertNonNegative(
            (string)$canonicalItem->getQtyRefunded(),
            'Native refunded quantity'
        );
        if ((string)$canonicalItem->getProductType() !== 'configurable') {
            return $parentRefunded;
        }

        $canonicalId = (int)$canonicalItem->getItemId();
        $orderId = (int)$canonicalItem->getOrderId();
        $childRefunded = '0.0000';
        foreach ($orderItems as $orderItem) {
            if (!$orderItem instanceof OrderItemInterface
                || (int)$orderItem->getParentItemId() !== $canonicalId
                || (int)$orderItem->getOrderId() !== $orderId
            ) {
                continue;
            }
            $childRefunded = $this->quantityMath->add(
                $childRefunded,
                $this->quantityMath->assertNonNegative(
                    (string)$orderItem->getQtyRefunded(),
                    'Native refunded quantity'
                )
            );
        }

        return $this->quantityMath->compare(
            $parentRefunded,
            $childRefunded
        ) >= 0
            ? $parentRefunded
            : $childRefunded;
    }
}
