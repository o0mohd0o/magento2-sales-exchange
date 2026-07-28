<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Api;

use Bonlineco\SalesExchange\Api\Data\ExchangeInterface;

/**
 * Atomically transition one exchange workflow dimension and append its audit record.
 *
 * @api
 */
interface TransitionExchangeInterface
{
    /**
     * @throws \Bonlineco\SalesExchange\Exception\InvariantViolationException
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function execute(
        int $exchangeId,
        int $expectedVersion,
        string $dimension,
        string $toStatus,
        string $actorType,
        ?int $actorId = null,
        ?string $comment = null
    ): ExchangeInterface;
}
