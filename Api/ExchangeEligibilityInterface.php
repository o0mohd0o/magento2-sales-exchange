<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Api;

use Magento\Sales\Api\Data\OrderInterface;

/**
 * Assert that an original order can start a new exchange case.
 *
 * @api
 */
interface ExchangeEligibilityInterface
{
    /**
     * @throws \Bonlineco\SalesExchange\Exception\InvariantViolationException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function execute(int $orderId): OrderInterface;
}
