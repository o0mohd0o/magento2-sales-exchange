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
 * Reconcile delivery only from the configured trusted proof provider.
 *
 * @api
 */
interface SynchronizeReplacementDeliveryInterface
{
    public function execute(int $replacementOrderId): ExchangeInterface;
}
