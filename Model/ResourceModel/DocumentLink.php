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
 * Append-only native-document link resource.
 */
class DocumentLink extends AbstractResource
{
    protected function _construct(): void
    {
        $this->_init('bonlineco_sales_exchange_document_link', 'entity_id');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getByOperationKeyForUpdate(string $operationKey): ?array
    {
        $select = $this->getConnection()
            ->select()
            ->from($this->getMainTable())
            ->where('operation_key = ?', $operationKey)
            ->forUpdate(true);
        $row = $this->getConnection()->fetchRow($select);

        return $row === false ? null : $row;
    }

    /**
     * Lock every document link for one exchange in deterministic order.
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
     * Document links are immutable after their first insert.
     */
    protected function _beforeSave(AbstractModel $object): self
    {
        if ($object->getId() !== null) {
            throw new InvariantViolationException(
                __('Native document links are append-only.')
            );
        }

        return parent::_beforeSave($object);
    }

    /**
     * Document links are permanent audit records.
     */
    protected function _beforeDelete(AbstractModel $object): self
    {
        throw new InvariantViolationException(__('Native document links cannot be deleted.'));
    }
}
