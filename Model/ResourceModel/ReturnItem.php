<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\ResourceModel;

use Bonlineco\SalesExchange\Api\Status\ExchangeStatus;
use Bonlineco\SalesExchange\Api\Status\ReturnStatus;
use Magento\Framework\DB\Sql\Expression;

/**
 * Return item resource model.
 */
class ReturnItem extends AbstractResource
{
    protected function _construct(): void
    {
        $this->_init('bonlineco_sales_exchange_return_item', 'entity_id');
    }

    /**
     * Fetch all return line snapshots for an exchange.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRowsByExchangeId(int $exchangeId): array
    {
        $select = $this->getConnection()
            ->select()
            ->from($this->getMainTable())
            ->where('exchange_id = ?', $exchangeId)
            ->order('order_item_id ASC');

        return $this->getConnection()->fetchAll($select);
    }

    /**
     * Fetch and lock every return line in deterministic order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRowsByExchangeIdForUpdate(int $exchangeId): array
    {
        $select = $this->getConnection()
            ->select()
            ->from($this->getMainTable())
            ->where('exchange_id = ?', $exchangeId)
            ->order('order_item_id ASC')
            ->forUpdate(true);

        return $this->getConnection()->fetchAll($select);
    }

    /**
     * Sum quantities reserved by exchange cases that were not cancelled.
     *
     * Accepted quantities remain reserved after completion until a later
     * document-execution phase atomically replaces that reservation with the
     * native Magento refunded quantity.
     */
    public function getAllocatedQuantity(int $orderItemId): string
    {
        $select = $this->getConnection()
            ->select()
            ->from(
                ['return_item' => $this->getMainTable()],
                ['allocated_qty' => $this->getAllocatedQuantityExpression()]
            )
            ->joinInner(
                ['exchange_case' => $this->getTable('bonlineco_sales_exchange')],
                'exchange_case.entity_id = return_item.exchange_id',
                []
            )
            ->where('return_item.order_item_id = ?', $orderItemId)
            ;

        return (string)$this->getConnection()->fetchOne($select);
    }

    /**
     * Sum every active exchange allocation belonging to one original order.
     *
     * @param int $orderId
     * @return string
     */
    public function getAllocatedQuantityForOrder(int $orderId): string
    {
        $select = $this->getConnection()
            ->select()
            ->from(
                ['return_item' => $this->getMainTable()],
                ['allocated_qty' => $this->getAllocatedQuantityExpression()]
            )
            ->joinInner(
                ['exchange_case' => $this->getTable('bonlineco_sales_exchange')],
                'exchange_case.entity_id = return_item.exchange_id',
                []
            )
            ->where('exchange_case.original_order_id = ?', $orderId);

        return (string)$this->getConnection()->fetchOne($select);
    }

    /**
     * Build the canonical active-allocation aggregate used by both queries.
     *
     * @return Expression
     */
    private function getAllocatedQuantityExpression(): Expression
    {
        return new Expression(
            sprintf(
                'COALESCE(SUM(CASE'
                . ' WHEN exchange_case.exchange_status = %s THEN 0'
                . ' WHEN exchange_case.return_status IN (%s, %s) THEN 0'
                . ' WHEN exchange_case.return_status IN (%s, %s)'
                . ' THEN GREATEST('
                . ' LEAST(return_item.allocated_qty, return_item.accepted_qty)'
                . ' - return_item.credited_qty, 0)'
                . ' ELSE return_item.allocated_qty END), 0)',
                $this->getConnection()->quote(ExchangeStatus::CANCELLED),
                $this->getConnection()->quote(ReturnStatus::CANCELLED),
                $this->getConnection()->quote(ReturnStatus::REJECTED),
                $this->getConnection()->quote(ReturnStatus::ACCEPTED),
                $this->getConnection()->quote(ReturnStatus::PARTIALLY_ACCEPTED)
            )
        );
    }
}
