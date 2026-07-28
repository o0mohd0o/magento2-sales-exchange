<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\Authorization;

use Bonlineco\SalesExchange\Model\Workflow\AdminActionMap;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\AuthorizationException;

/**
 * Enforce the complete authorization boundary for exchange-order reads.
 */
class ExchangeReadAuthorization
{
    private AuthorizationInterface $authorization;

    public function __construct(AuthorizationInterface $authorization)
    {
        $this->authorization = $authorization;
    }

    public function isAllowed(): bool
    {
        $exchangeViewAllowed = $this->authorization->isAllowed(
            AdminActionMap::ACL_VIEW
        );
        $salesOrderViewAllowed = $this->authorization->isAllowed(
            AdminActionMap::ACL_SALES_ORDER_VIEW
        );

        return $exchangeViewAllowed && $salesOrderViewAllowed;
    }

    /**
     * @throws AuthorizationException
     */
    public function assertAllowed(): void
    {
        if (!$this->isAllowed()) {
            throw new AuthorizationException(
                __('You are not authorized to view exchange order data.')
            );
        }
    }
}
