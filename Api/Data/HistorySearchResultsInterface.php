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
 * Audit history search results.
 *
 * @api
 */
interface HistorySearchResultsInterface extends SearchResultsInterface
{
    /**
     * @return \Bonlineco\SalesExchange\Api\Data\HistoryInterface[]
     */
    public function getItems();

    /**
     * @param \Bonlineco\SalesExchange\Api\Data\HistoryInterface[] $items
     * @return $this
     */
    public function setItems(array $items);
}
