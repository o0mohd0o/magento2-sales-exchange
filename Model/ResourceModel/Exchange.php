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
 * Exchange case resource model.
 */
class Exchange extends AbstractResource
{
    protected function _construct(): void
    {
        $this->_init('bonlineco_sales_exchange', 'entity_id');
    }

    /**
     * Persist a compare-and-swap aggregate version increment.
     */
    public function updateVersion(
        int $exchangeId,
        int $expectedVersion,
        int $nextVersion
    ): bool {
        $affected = $this->getConnection()->update(
            $this->getMainTable(),
            ['version' => $nextVersion],
            [
                'entity_id = ?' => $exchangeId,
                'version = ?' => $expectedVersion,
            ]
        );

        return $affected === 1;
    }

    /**
     * Exchange cases are cancelled and retained, never deleted.
     *
     * @throws InvariantViolationException
     */
    protected function _beforeDelete(AbstractModel $object): self
    {
        throw new InvariantViolationException(__('Exchange cases cannot be deleted; cancel the case instead.'));
    }
}
