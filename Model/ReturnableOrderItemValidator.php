<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model;

use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Magento\Sales\Api\Data\OrderItemInterface;

/**
 * Resolve the canonical physical order line supported by the foundation.
 */
class ReturnableOrderItemValidator
{
    private const SUPPORTED_PRODUCT_TYPES = ['simple', 'configurable'];

    /**
     * Configurable children are rejected in favor of their visible parent.
     * Bundle and other composite types are deferred until their quantity and
     * pricing semantics can be represented without double allocation.
     *
     * @throws InvariantViolationException
     */
    public function execute(OrderItemInterface $orderItem): void
    {
        if ((int)$orderItem->getParentItemId() > 0) {
            throw new InvariantViolationException(
                __('Select the visible parent order item instead of a child component.')
            );
        }

        if (!in_array((string)$orderItem->getProductType(), self::SUPPORTED_PRODUCT_TYPES, true)) {
            throw new InvariantViolationException(
                __('Only simple and configurable order items are supported for exchanges.')
            );
        }
    }
}
