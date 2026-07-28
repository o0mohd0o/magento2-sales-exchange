<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Controller\Adminhtml\Exchange;

use Bonlineco\SalesExchange\Model\Workflow\AdminActionMap;
use Magento\Backend\App\Action;

/**
 * Require both exchange-create and native sales-order view permissions.
 */
abstract class CreationAction extends Action
{
    public const ADMIN_RESOURCE = AdminActionMap::ACL_CREATE;

    protected function _isAllowed(): bool
    {
        return parent::_isAllowed()
            && $this->_authorization->isAllowed(AdminActionMap::ACL_SALES_ORDER_VIEW);
    }
}
