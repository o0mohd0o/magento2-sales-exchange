<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Api;

use Bonlineco\SalesExchange\Api\Data\CreateExchangeRequestInterface;
use Bonlineco\SalesExchange\Api\Data\ExchangeInterface;

/**
 * Atomically create a draft exchange case and its selected lines.
 *
 * @api
 */
interface CreateExchangeInterface
{
    /**
     * @throws \Bonlineco\SalesExchange\Exception\InvariantViolationException
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     */
    public function execute(CreateExchangeRequestInterface $request): ExchangeInterface;
}
