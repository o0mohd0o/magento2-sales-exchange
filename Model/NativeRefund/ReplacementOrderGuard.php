<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\NativeRefund;

use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Order\FreshOrderLoader;
use Bonlineco\SalesExchange\Model\ReplacementOrder\MarkerReader;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Fail closed until replacement-order refunds have a compensation workflow.
 */
class ReplacementOrderGuard
{
    private FreshOrderLoader $freshOrderLoader;

    private MarkerReader $markerReader;

    public function __construct(
        FreshOrderLoader $freshOrderLoader,
        MarkerReader $markerReader
    ) {
        $this->freshOrderLoader = $freshOrderLoader;
        $this->markerReader = $markerReader;
    }

    public function execute(
        CreditmemoInterface $creditmemo,
        OrderInterface $passedOrder
    ): void {
        $orderId = (int)$creditmemo->getOrderId();
        if ($orderId <= 0 || (int)$passedOrder->getEntityId() !== $orderId) {
            throw new InvariantViolationException(
                __('The native credit memo does not match its original order.')
            );
        }

        $freshOrder = $this->freshOrderLoader->execute($orderId);
        if ($this->markerReader->execute($freshOrder) !== null) {
            throw new InvariantViolationException(
                __(
                    'Native refunds of a replacement order are not supported. '
                    . 'Use the exchange compensation workflow.'
                )
            );
        }
    }
}
