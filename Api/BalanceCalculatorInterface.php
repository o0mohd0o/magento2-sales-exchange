<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Api;

/**
 * Calculate the signed customer/merchant exchange balance.
 *
 * @api
 */
interface BalanceCalculatorInterface
{
    /**
     * Positive means customer due; negative means merchant refund due.
     *
     * @throws \Bonlineco\SalesExchange\Exception\InvariantViolationException
     */
    public function execute(
        string $replacementAmount,
        string $shippingAmount,
        string $feeAmount,
        string $returnCreditAmount
    ): string;
}
