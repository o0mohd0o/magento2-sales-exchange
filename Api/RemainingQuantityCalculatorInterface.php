<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Api;

/**
 * Calculate remaining quantity eligible for a new exchange allocation.
 *
 * @api
 */
interface RemainingQuantityCalculatorInterface
{
    /**
     * Calculate invoice-backed quantity not yet refunded or reserved.
     *
     * @throws \Bonlineco\SalesExchange\Exception\InvariantViolationException
     */
    public function execute(
        string $invoicedQuantity,
        string $refundedQuantity,
        string $activeAllocatedQuantity
    ): string;
}
