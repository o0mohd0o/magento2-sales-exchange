<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Api;

use Bonlineco\SalesExchange\Api\Data\SettlementInterface;
use Bonlineco\SalesExchange\Api\Data\SettlementSearchResultsInterface;
use Magento\Framework\Api\SearchCriteriaInterface;

/**
 * Idempotent settlement ledger persistence contract.
 *
 * @api
 */
interface SettlementRepositoryInterface
{
    public function save(SettlementInterface $settlement): SettlementInterface;

    public function getById(int $settlementId): SettlementInterface;

    public function getByIdempotencyKey(string $idempotencyKey): SettlementInterface;

    public function getList(SearchCriteriaInterface $searchCriteria): SettlementSearchResultsInterface;
}
