<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Api;

use Bonlineco\SalesExchange\Api\Data\DocumentLinkInterface;

/**
 * Create the next idempotent offline native credit memo for an accepted return.
 *
 * @api
 */
interface CreateCreditmemoInterface
{
    /**
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function execute(
        int $exchangeId,
        int $expectedVersion,
        int $actorId,
        ?string $comment = null
    ): DocumentLinkInterface;
}
