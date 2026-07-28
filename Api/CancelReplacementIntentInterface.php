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
 * Cancel an unplaced replacement intent without deleting its audit snapshot.
 *
 * @api
 */
interface CancelReplacementIntentInterface
{
    /**
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function execute(
        int $exchangeId,
        int $expectedVersion,
        int $actorId,
        ?string $comment = null
    ): ExchangeInterface;
}
