<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Api;

/**
 * Validate a proposed allocation against a remaining quantity snapshot.
 *
 * @api
 */
interface AllocationValidatorInterface
{
    /**
     * @throws \Bonlineco\SalesExchange\Exception\InvariantViolationException
     */
    public function execute(string $requestedQuantity, string $remainingQuantity): void;
}
