<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Api;

use Bonlineco\SalesExchange\Api\Data\ReturnItemInterface;
use Bonlineco\SalesExchange\Api\Data\ReturnItemSearchResultsInterface;
use Magento\Framework\Api\SearchCriteriaInterface;

/**
 * Read-only return item contract.
 *
 * @api
 */
interface ReturnItemRepositoryInterface
{
    public function getById(int $returnItemId): ReturnItemInterface;

    public function getList(SearchCriteriaInterface $searchCriteria): ReturnItemSearchResultsInterface;
}
