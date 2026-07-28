<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Api\Data;

use Magento\Framework\Api\SearchResultsInterface;

/**
 * Return item search results.
 *
 * @api
 */
interface ReturnItemSearchResultsInterface extends SearchResultsInterface
{
    /**
     * @return \Bonlineco\SalesExchange\Api\Data\ReturnItemInterface[]
     */
    public function getItems();

    /**
     * @param \Bonlineco\SalesExchange\Api\Data\ReturnItemInterface[] $items
     * @return $this
     */
    public function setItems(array $items);
}
