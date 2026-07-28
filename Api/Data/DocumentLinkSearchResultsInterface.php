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
 * @api
 */
interface DocumentLinkSearchResultsInterface extends SearchResultsInterface
{
    /**
     * @return \Bonlineco\SalesExchange\Api\Data\DocumentLinkInterface[]
     */
    public function getItems();

    /**
     * @param \Bonlineco\SalesExchange\Api\Data\DocumentLinkInterface[] $items
     * @return $this
     */
    public function setItems(array $items);
}
