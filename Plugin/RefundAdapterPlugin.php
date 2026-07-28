<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Plugin;

use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\NativeRefund\ReplacementOrderGuard;
use Bonlineco\SalesExchange\Model\NativeRefund\ReservationGuard;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order\RefundAdapter;
use Magento\Sales\Model\OrderMutexInterface;

/**
 * Serialize native refunds and protect active exchange reservations.
 */
class RefundAdapterPlugin
{
    private OrderMutexInterface $orderMutex;

    private ReservationGuard $reservationGuard;

    private ReplacementOrderGuard $replacementOrderGuard;

    public function __construct(
        OrderMutexInterface $orderMutex,
        ReservationGuard $reservationGuard,
        ReplacementOrderGuard $replacementOrderGuard
    ) {
        $this->orderMutex = $orderMutex;
        $this->reservationGuard = $reservationGuard;
        $this->replacementOrderGuard = $replacementOrderGuard;
    }

    /**
     * An around plugin is required so validation and the native write share
     * the same sales-order mutex transaction.
     *
     * @param callable $proceed
     * @param bool $isOnline
     */
    public function aroundRefund(
        RefundAdapter $subject,
        callable $proceed,
        CreditmemoInterface $creditmemo,
        OrderInterface $order,
        $isOnline = false
    ): OrderInterface {
        unset($subject);
        $orderId = (int)$creditmemo->getOrderId();
        if ($orderId <= 0 || (int)$order->getEntityId() !== $orderId) {
            throw new InvariantViolationException(
                __('The native credit memo does not match its original order.')
            );
        }

        /** @var OrderInterface $refundedOrder */
        $refundedOrder = $this->orderMutex->execute(
            $orderId,
            function () use ($proceed, $creditmemo, $order, $isOnline): OrderInterface {
                $this->replacementOrderGuard->execute(
                    $creditmemo,
                    $order
                );
                $this->reservationGuard->execute($creditmemo, $order);

                /** @var OrderInterface $result */
                $result = $proceed($creditmemo, $order, $isOnline);

                return $result;
            }
        );

        return $refundedOrder;
    }
}
