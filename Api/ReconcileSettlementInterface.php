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
 * Reconcile the native replacement invoice and the exchange settlement ledger.
 *
 * @api
 */
interface ReconcileSettlementInterface
{
    /**
     * @param int $exchangeId
     * @param int $expectedVersion
     * @param int $actorId
     * @param string|null $externalReference Required for customer payment or merchant refund.
     * @param string|null $comment
     */
    public function execute(
        int $exchangeId,
        int $expectedVersion,
        int $actorId,
        ?string $externalReference = null,
        ?string $comment = null
    ): ExchangeInterface;
}
