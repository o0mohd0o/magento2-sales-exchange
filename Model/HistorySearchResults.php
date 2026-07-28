<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model;

use Bonlineco\SalesExchange\Api\Data\HistorySearchResultsInterface;
use Magento\Framework\Api\SearchResults;

/**
 * Audit history search results implementation.
 */
class HistorySearchResults extends SearchResults implements HistorySearchResultsInterface
{
}
