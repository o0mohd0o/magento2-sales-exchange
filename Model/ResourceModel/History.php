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
 * Append-only audit history resource model.
 */
class History extends AbstractResource
{
    protected function _construct(): void
    {
        $this->_init('bonlineco_sales_exchange_history', 'entity_id');
    }

    /**
     * Lock audit rows in append order for exact idempotent replay checks.
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
     * Reject updates to existing audit rows.
     *
     * @throws InvariantViolationException
     */
    protected function _beforeSave(AbstractModel $object): self
    {
        if ($object->getId() !== null) {
            throw new InvariantViolationException(__('Exchange audit history is append-only.'));
        }

        return parent::_beforeSave($object);
    }

    /**
     * Reject deletion of audit rows.
     *
     * @throws InvariantViolationException
     */
    protected function _beforeDelete(AbstractModel $object): self
    {
        throw new InvariantViolationException(__('Exchange audit history cannot be deleted.'));
    }
}
