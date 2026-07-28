<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Plugin;

use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Model\OrderMutexInterface;
use Magento\Sales\Model\Service\CreditmemoService;

/**
 * Lock the order before legacy CreditmemoService mutates its invoice.
 */
class CreditmemoServicePlugin
{
    private OrderMutexInterface $orderMutex;

    public function __construct(OrderMutexInterface $orderMutex)
    {
        $this->orderMutex = $orderMutex;
    }

    /**
     * An around plugin is required because invoice persistence happens before
     * CreditmemoService reaches RefundAdapter.
     *
     * @param callable $proceed
     * @param bool $offlineRequested
     */
    public function aroundRefund(
        CreditmemoService $subject,
        callable $proceed,
        CreditmemoInterface $creditmemo,
        $offlineRequested = false
    ): CreditmemoInterface {
        unset($subject);
        $orderId = (int)$creditmemo->getOrderId();
        if ($orderId <= 0) {
            throw new InvariantViolationException(
                __('The native credit memo requires an original order.')
            );
        }

        /** @var CreditmemoInterface $result */
        $result = $this->orderMutex->execute(
            $orderId,
            static function () use (
                $proceed,
                $creditmemo,
                $offlineRequested
            ): CreditmemoInterface {
                /** @var CreditmemoInterface $refunded */
                $refunded = $proceed($creditmemo, $offlineRequested);

                return $refunded;
            }
        );

        return $result;
    }
}
