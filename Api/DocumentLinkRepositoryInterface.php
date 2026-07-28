<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Api;

use Bonlineco\SalesExchange\Api\Data\DocumentLinkInterface;
use Bonlineco\SalesExchange\Api\Data\DocumentLinkSearchResultsInterface;
use Magento\Framework\Api\SearchCriteriaInterface;

/**
 * Read immutable native-document links.
 *
 * @api
 */
interface DocumentLinkRepositoryInterface
{
    public function getById(int $documentLinkId): DocumentLinkInterface;

    public function getByOperationKey(string $operationKey): DocumentLinkInterface;

    public function getList(SearchCriteriaInterface $searchCriteria): DocumentLinkSearchResultsInterface;
}
