<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Plugin;

use Bonlineco\SalesExchange\Model\Payment\Replacement;
use Bonlineco\SalesExchange\Model\ReplacementOrder\ExecutionContext;
use Magento\Payment\Model\Checks\ZeroTotal;
use Magento\Payment\Model\MethodInterface;
use Magento\Quote\Model\Quote;

/**
 * Permit this module's non-free payment code on its trusted zero-total quote.
 */
class ZeroTotalPlugin
{
    /**
     * Trusted replacement-order execution state.
     *
     * @var ExecutionContext
     */
    private ExecutionContext $executionContext;

    /**
     * @param ExecutionContext $executionContext
     */
    public function __construct(ExecutionContext $executionContext)
    {
        $this->executionContext = $executionContext;
    }

    /**
     * Allow only the module's trusted payment method through the zero-total gate.
     *
     * @param ZeroTotal $subject
     * @param bool $result
     * @param MethodInterface $paymentMethod
     * @param Quote $quote
     * @return bool
     */
    public function afterIsApplicable(
        ZeroTotal $subject,
        bool $result,
        MethodInterface $paymentMethod,
        Quote $quote
    ): bool {
        unset($subject);
        if ($result || $paymentMethod->getCode() !== Replacement::CODE) {
            return $result;
        }

        return $this->executionContext->isTrustedQuote($quote);
    }
}
