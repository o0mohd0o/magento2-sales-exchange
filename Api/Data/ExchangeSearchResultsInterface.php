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
 * Exchange case search results.
 *
 * @api
 */
interface ExchangeSearchResultsInterface extends SearchResultsInterface
{
    /**
     * @return \Bonlineco\SalesExchange\Api\Data\ExchangeInterface[]
     */
    public function getItems();

    /**
     * @param \Bonlineco\SalesExchange\Api\Data\ExchangeInterface[] $items
     * @return $this
     */
    public function setItems(array $items);
}
