<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Api;

use Bonlineco\SalesExchange\Api\Data\HistoryInterface;
use Bonlineco\SalesExchange\Api\Data\HistorySearchResultsInterface;
use Magento\Framework\Api\SearchCriteriaInterface;

/**
 * Read-only access to append-only exchange audit history.
 *
 * @api
 */
interface HistoryRepositoryInterface
{
    public function getById(int $historyId): HistoryInterface;

    public function getList(SearchCriteriaInterface $searchCriteria): HistorySearchResultsInterface;
}
