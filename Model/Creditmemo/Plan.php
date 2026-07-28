<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\Creditmemo;

/**
 * Immutable normalized input for one native credit memo operation.
 */
class Plan
{
    /**
     * @param array<int, string> $quantitiesByOrderItem
     * @param array<int, array{quantity: string, credited_qty: string}> $returnItemUpdates
     * @param int[] $returnToStockOrderItemIds
     */
    public function __construct(
        private array $quantitiesByOrderItem,
        private array $returnItemUpdates,
        private array $returnToStockOrderItemIds
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function getQuantitiesByOrderItem(): array
    {
        return $this->quantitiesByOrderItem;
    }

    /**
     * @return array<int, array{quantity: string, credited_qty: string}>
     */
    public function getReturnItemUpdates(): array
    {
        return $this->returnItemUpdates;
    }

    /**
     * @return int[]
     */
    public function getReturnToStockOrderItemIds(): array
    {
        return $this->returnToStockOrderItemIds;
    }
}
