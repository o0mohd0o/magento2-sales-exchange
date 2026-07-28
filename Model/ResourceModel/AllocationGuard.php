<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * Serialize allocation changes for one original order item.
 */
class AllocationGuard
{
    private const TABLE = 'bonlineco_sales_exchange_allocation_guard';

    private ResourceConnection $resourceConnection;

    public function __construct(ResourceConnection $resourceConnection)
    {
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * Create and lock the module-owned guard row inside the caller's transaction.
     */
    public function lock(int $orderItemId): void
    {
        $connection = $this->resourceConnection->getConnection('sales');
        $table = $this->resourceConnection->getTableName(self::TABLE);
        $connection->insertOnDuplicate(
            $table,
            ['order_item_id' => $orderItemId],
            ['order_item_id']
        );
        $select = $connection->select()
            ->from($table, ['order_item_id'])
            ->where('order_item_id = ?', $orderItemId)
            ->forUpdate(true);
        $connection->fetchOne($select);
    }
}
