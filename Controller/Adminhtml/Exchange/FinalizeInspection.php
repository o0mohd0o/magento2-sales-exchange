<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Controller\Adminhtml\Exchange;

use Bonlineco\SalesExchange\Api\AdminAction;
use Bonlineco\SalesExchange\Model\Workflow\AdminActionMap;
use Magento\Framework\App\Action\HttpPostActionInterface;

class FinalizeInspection extends WorkflowAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = AdminActionMap::ACL_WAREHOUSE;
    protected const ACTION = AdminAction::FINALIZE_INSPECTION;
}
