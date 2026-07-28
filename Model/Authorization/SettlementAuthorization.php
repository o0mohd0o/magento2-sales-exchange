<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\Authorization;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\AuthorizationException;

/**
 * Enforce the complete authorization boundary for settlement reconciliation.
 *
 * The native invoice grant is intentionally required even for a refund-only
 * settlement because controller authorization must not depend on request
 * state that can change before the locked command executes.
 */
class SettlementAuthorization
{
    public const ACL_SETTLEMENT = 'Bonlineco_SalesExchange::settlement';
    public const ACL_NATIVE_INVOICE = 'Magento_Sales::invoice';

    private ExchangeReadAuthorization $readAuthorization;

    private AuthorizationInterface $authorization;

    public function __construct(
        ExchangeReadAuthorization $readAuthorization,
        AuthorizationInterface $authorization
    ) {
        $this->readAuthorization = $readAuthorization;
        $this->authorization = $authorization;
    }

    public function isAllowed(): bool
    {
        return $this->readAuthorization->isAllowed()
            && $this->authorization->isAllowed(self::ACL_SETTLEMENT)
            && $this->authorization->isAllowed(self::ACL_NATIVE_INVOICE);
    }

    /**
     * @throws AuthorizationException
     */
    public function assertAllowed(): void
    {
        if (!$this->isAllowed()) {
            throw new AuthorizationException(
                __('You are not authorized to reconcile exchange settlements.')
            );
        }
    }
}
