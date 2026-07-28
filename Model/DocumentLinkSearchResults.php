<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model;

use Bonlineco\SalesExchange\Api\Data\DocumentLinkSearchResultsInterface;
use Magento\Framework\Api\SearchResults;

class DocumentLinkSearchResults extends SearchResults implements DocumentLinkSearchResultsInterface
{
}
