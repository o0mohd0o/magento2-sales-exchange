<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\ResourceModel;

/**
 * Replacement item resource model.
 */
class ReplacementItem extends AbstractResource
{
    protected function _construct(): void
    {
        $this->_init('bonlineco_sales_exchange_replacement_item', 'entity_id');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRowsByExchangeId(int $exchangeId): array
    {
        $select = $this->getConnection()
            ->select()
            ->from($this->getMainTable())
            ->where('exchange_id = ?', $exchangeId)
            ->order('entity_id ASC');

        return $this->getConnection()->fetchAll($select);
    }

    /**
     * Fetch and lock replacement rows in the canonical child-lock order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRowsByExchangeIdForUpdate(int $exchangeId): array
    {
        $select = $this->getConnection()
            ->select()
            ->from($this->getMainTable())
            ->where('exchange_id = ?', $exchangeId)
            ->order('entity_id ASC')
            ->forUpdate(true);

        return $this->getConnection()->fetchAll($select);
    }
}
