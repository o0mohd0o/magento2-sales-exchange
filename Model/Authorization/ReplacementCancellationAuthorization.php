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
 * Enforce the dedicated permission boundary for replacement compensation.
 */
class ReplacementCancellationAuthorization
{
    public const ACL_REPLACEMENT_CANCEL =
        'Bonlineco_SalesExchange::replacement_cancel';

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
            && $this->authorization->isAllowed(AdminActionMap::ACL_CANCEL)
            && $this->authorization->isAllowed(self::ACL_REPLACEMENT_CANCEL);
    }

    /**
     * @throws AuthorizationException
     */
    public function assertAllowed(): void
    {
        if (!$this->isAllowed()) {
            throw new AuthorizationException(
                __('You are not authorized to cancel exchange replacement intents.')
            );
        }
    }
}
