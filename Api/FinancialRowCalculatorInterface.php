<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Api;

/**
 * Calculate a canonical money row from a quantity and unit amount.
 *
 * Products are rounded half-up to the module's four-decimal money scale.
 *
 * @api
 */
interface FinancialRowCalculatorInterface
{
    /**
     * @throws \Bonlineco\SalesExchange\Exception\InvariantViolationException
     */
    public function execute(string $quantity, string $unitAmount): string;
}
