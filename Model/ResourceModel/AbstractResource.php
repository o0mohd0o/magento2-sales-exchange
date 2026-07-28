<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\Context;

/**
 * Base resource using Magento's sales connection.
 *
 * @SuppressWarnings(PHPMD.NumberOfChildren)
 */
abstract class AbstractResource extends AbstractDb
{
    public function __construct(Context $context, ?string $connectionName = null)
    {
        parent::__construct($context, $connectionName ?? 'sales');
    }

    /**
     * Fetch and lock an entity row inside the caller's transaction.
     *
     * @return array<string, mixed>|null
     */
    public function getDataForUpdate(int $entityId): ?array
    {
        $select = $this->getConnection()
            ->select()
            ->from($this->getMainTable())
            ->where($this->getIdFieldName() . ' = ?', $entityId)
            ->forUpdate(true);
        $data = $this->getConnection()->fetchRow($select);

        return $data === false ? null : $data;
    }
}
