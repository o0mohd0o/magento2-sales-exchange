<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model;

use Bonlineco\SalesExchange\Api\Data\SettlementSearchResultsInterface;
use Magento\Framework\Api\SearchResults;

/**
 * Settlement search results implementation.
 */
class SettlementSearchResults extends SearchResults implements SettlementSearchResultsInterface
{
}
