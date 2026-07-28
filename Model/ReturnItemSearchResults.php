<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model;

use Bonlineco\SalesExchange\Api\Data\ReturnItemSearchResultsInterface;
use Magento\Framework\Api\SearchResults;

/**
 * Return item search results implementation.
 */
class ReturnItemSearchResults extends SearchResults implements ReturnItemSearchResultsInterface
{
}
