<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\ResourceModel\ReturnItem;

use Bonlineco\SalesExchange\Model\ResourceModel\ReturnItem as ReturnItemResource;
use Bonlineco\SalesExchange\Model\ReturnItem;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * Return item collection.
 */
class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(ReturnItem::class, ReturnItemResource::class);
    }
}
