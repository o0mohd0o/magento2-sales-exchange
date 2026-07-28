<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\ResourceModel\History;

use Bonlineco\SalesExchange\Model\History;
use Bonlineco\SalesExchange\Model\ResourceModel\History as HistoryResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * Audit history collection.
 */
class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(History::class, HistoryResource::class);
    }
}
