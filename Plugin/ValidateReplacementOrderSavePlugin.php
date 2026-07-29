<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Plugin;

use Bonlineco\SalesExchange\Model\ReplacementOrder\ExecutionContext;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\OrderRepository;

/**
 * Revalidate the converted replacement order after place events, before save.
 */
class ValidateReplacementOrderSavePlugin
{
    private ExecutionContext $executionContext;

    public function __construct(ExecutionContext $executionContext)
    {
        $this->executionContext = $executionContext;
    }

    /**
     * @param callable $proceed
     */
    public function aroundSave(
        OrderRepository $subject,
        callable $proceed,
        OrderInterface $order
    ): OrderInterface {
        unset($subject);

        if (!$this->executionContext->hasPrePlaceOrderValidator()) {
            return $proceed($order);
        }
        if (!$this->executionContext->isTrustedOrder($order)) {
            throw new InvariantViolationException(
                __('Magento attempted to save an untrusted replacement order.')
            );
        }

        $this->executionContext->validateBeforePlace($order);
        $savedOrder = $proceed($order);
        if (!$savedOrder instanceof OrderInterface) {
            throw new InvariantViolationException(
                __('Magento returned an invalid saved replacement order.')
            );
        }
        $this->executionContext->validateAfterSave($savedOrder);

        return $savedOrder;
    }
}
