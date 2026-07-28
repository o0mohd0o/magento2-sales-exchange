<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Api;

use Bonlineco\SalesExchange\Api\Data\ReplacementItemInterface;
use Bonlineco\SalesExchange\Api\Data\ReplacementItemSearchResultsInterface;
use Magento\Framework\Api\SearchCriteriaInterface;

/**
 * Read-only replacement item contract.
 *
 * @api
 */
interface ReplacementItemRepositoryInterface
{
    public function getById(int $replacementItemId): ReplacementItemInterface;

    public function getList(SearchCriteriaInterface $searchCriteria): ReplacementItemSearchResultsInterface;
}
