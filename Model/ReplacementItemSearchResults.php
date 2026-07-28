<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model;

use Bonlineco\SalesExchange\Api\Data\ReplacementItemSearchResultsInterface;
use Magento\Framework\Api\SearchResults;

/**
 * Replacement item search results implementation.
 */
class ReplacementItemSearchResults extends SearchResults implements ReplacementItemSearchResultsInterface
{
}
