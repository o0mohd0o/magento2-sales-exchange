<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model;

use Bonlineco\SalesExchange\Api\Data\ExchangeSearchResultsInterface;
use Magento\Framework\Api\SearchResults;

/**
 * Exchange search results implementation.
 */
class ExchangeSearchResults extends SearchResults implements ExchangeSearchResultsInterface
{
}
