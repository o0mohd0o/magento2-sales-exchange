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
 * Enforce the complete authorization boundary for replacement-order creation.
 */
class ReplacementOrderAuthorization
{
    public const ACL_REPLACEMENT_ORDER = 'Bonlineco_SalesExchange::replacement_order';
    public const ACL_NATIVE_SALES_CREATE = 'Magento_Sales::create';

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
            && $this->authorization->isAllowed(self::ACL_REPLACEMENT_ORDER)
            && $this->authorization->isAllowed(self::ACL_NATIVE_SALES_CREATE);
    }

    /**
     * @throws AuthorizationException
     */
    public function assertAllowed(): void
    {
        if (!$this->isAllowed()) {
            throw new AuthorizationException(
                __('You are not authorized to create exchange replacement orders.')
            );
        }
    }
}
