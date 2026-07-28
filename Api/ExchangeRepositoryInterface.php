<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Api;

use Bonlineco\SalesExchange\Api\Data\ExchangeInterface;
use Bonlineco\SalesExchange\Api\Data\ExchangeSearchResultsInterface;
use Magento\Framework\Api\SearchCriteriaInterface;

/**
 * Read-only exchange case contract.
 *
 * Supported writes use CreateExchangeInterface, TransitionExchangeInterface,
 * warehouse commands, and native-document commands.
 *
 * @api
 */
interface ExchangeRepositoryInterface
{
    public function getById(int $exchangeId): ExchangeInterface;

    public function getByIncrementId(string $incrementId): ExchangeInterface;

    public function getList(SearchCriteriaInterface $searchCriteria): ExchangeSearchResultsInterface;
}
