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
 * Enforce the complete authorization boundary for exchange financial actions.
 */
class ExchangeFinancialAuthorization
{
    public const ACL_FINANCIAL = 'Bonlineco_SalesExchange::financial';
    public const ACL_NATIVE_CREDITMEMO = 'Magento_Sales::creditmemo';

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
            && $this->authorization->isAllowed(self::ACL_FINANCIAL)
            && $this->authorization->isAllowed(self::ACL_NATIVE_CREDITMEMO);
    }

    /**
     * @throws AuthorizationException
     */
    public function assertAllowed(): void
    {
        if (!$this->isAllowed()) {
            throw new AuthorizationException(
                __('You are not authorized to perform exchange financial actions.')
            );
        }
    }
}
