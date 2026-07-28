<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\ResourceModel\ReplacementItem;

use Bonlineco\SalesExchange\Model\ReplacementItem;
use Bonlineco\SalesExchange\Model\ResourceModel\ReplacementItem as ReplacementItemResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * Replacement item collection.
 */
class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(ReplacementItem::class, ReplacementItemResource::class);
    }
}
