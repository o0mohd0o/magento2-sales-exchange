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
 * Settlement entry search results.
 *
 * @api
 */
interface SettlementSearchResultsInterface extends SearchResultsInterface
{
    /**
     * @return \Bonlineco\SalesExchange\Api\Data\SettlementInterface[]
     */
    public function getItems();

    /**
     * @param \Bonlineco\SalesExchange\Api\Data\SettlementInterface[] $items
     * @return $this
     */
    public function setItems(array $items);
}
