<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\ResourceModel\Exchange;

use Bonlineco\SalesExchange\Model\Exchange;
use Bonlineco\SalesExchange\Model\ResourceModel\Exchange as ExchangeResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * Exchange case collection.
 */
class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(Exchange::class, ExchangeResource::class);
    }
}
