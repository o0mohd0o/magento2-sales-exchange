<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\ResourceModel\Settlement;

use Bonlineco\SalesExchange\Model\ResourceModel\Settlement as SettlementResource;
use Bonlineco\SalesExchange\Model\Settlement;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * Settlement ledger collection.
 */
class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(Settlement::class, SettlementResource::class);
    }
}
