<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\ResourceModel;

use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Magento\Framework\Model\AbstractModel;

/**
 * Settlement ledger resource model.
 */
class Settlement extends AbstractResource
{
    protected function _construct(): void
    {
        $this->_init('bonlineco_sales_exchange_settlement', 'entity_id');
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
     * Lock every ledger row for an exchange in append order.
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

    /**
     * @return array<string, mixed>|null
     */
    public function getByIdempotencyKeyForUpdate(string $idempotencyKey): ?array
    {
        $select = $this->getConnection()
            ->select()
            ->from($this->getMainTable())
            ->where('idempotency_key = ?', $idempotencyKey)
            ->forUpdate(true);
        $row = $this->getConnection()->fetchRow($select);

        return $row === false ? null : $row;
    }

    /**
     * Ledger entries are immutable postings.
     */
    protected function _beforeSave(AbstractModel $object): self
    {
        if ($object->getId() !== null
            && str_starts_with(
                (string)$object->getData('idempotency_key'),
                'sales-exchange:settlement:'
            )
        ) {
            throw new InvariantViolationException(
                __('Canonical settlement ledger postings are append-only.')
            );
        }

        return parent::_beforeSave($object);
    }

    /**
     * Ledger entries are retained for financial audit.
     *
     * @throws InvariantViolationException
     */
    protected function _beforeDelete(AbstractModel $object): self
    {
        throw new InvariantViolationException(__('Settlement ledger entries cannot be deleted.'));
    }
}
