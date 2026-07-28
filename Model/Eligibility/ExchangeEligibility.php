<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\Eligibility;

use Bonlineco\SalesExchange\Api\ConfigInterface;
use Bonlineco\SalesExchange\Api\ExchangeEligibilityInterface;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Bonlineco\SalesExchange\Model\Order\FreshOrderLoader;
use Bonlineco\SalesExchange\Model\OrderItemRemainingQuantity;
use Bonlineco\SalesExchange\Model\ReturnableOrderItemValidator;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;

/**
 * Resolve an original order and enforce store plus quantity eligibility.
 */
class ExchangeEligibility implements ExchangeEligibilityInterface
{
    private FreshOrderLoader $freshOrderLoader;

    private ConfigInterface $config;

    private EligibilityPolicy $policy;

    private DateTime $dateTime;

    private ReturnableOrderItemValidator $returnableOrderItemValidator;

    private OrderItemRemainingQuantity $orderItemRemainingQuantity;

    private DecimalMath $quantityMath;

    public function __construct(
        FreshOrderLoader $freshOrderLoader,
        ConfigInterface $config,
        EligibilityPolicy $policy,
        DateTime $dateTime,
        ReturnableOrderItemValidator $returnableOrderItemValidator,
        OrderItemRemainingQuantity $orderItemRemainingQuantity,
        DecimalMath $quantityMath
    ) {
        $this->freshOrderLoader = $freshOrderLoader;
        $this->config = $config;
        $this->policy = $policy;
        $this->dateTime = $dateTime;
        $this->returnableOrderItemValidator = $returnableOrderItemValidator;
        $this->orderItemRemainingQuantity = $orderItemRemainingQuantity;
        $this->quantityMath = $quantityMath;
    }

    public function execute(int $orderId): OrderInterface
    {
        if ($orderId <= 0) {
            throw new InvariantViolationException(__('A valid original order is required.'));
        }
        $order = $this->freshOrderLoader->execute($orderId);
        $storeId = $order->getStoreId() === null ? null : (int)$order->getStoreId();
        $this->policy->execute(
            $this->config->isEnabled($storeId),
            (string)$order->getStatus(),
            $this->config->getEligibleOrderStatuses($storeId),
            $this->dateTime->gmtTimestamp((string)$order->getCreatedAt()),
            $this->dateTime->gmtTimestamp(),
            $this->config->getExchangeWindowDays($storeId),
            $this->hasReturnableQuantity($order)
        );

        return $order;
    }

    private function hasReturnableQuantity(OrderInterface $order): bool
    {
        /** @var array<int, OrderItemInterface> $orderItems */
        $orderItems = (array)$order->getItems();
        foreach ($orderItems as $orderItem) {
            if (!$orderItem instanceof OrderItemInterface) {
                continue;
            }
            try {
                $this->returnableOrderItemValidator->execute($orderItem);
                $remaining = $this->orderItemRemainingQuantity->execute(
                    $orderItem,
                    $orderItems
                );
                if ($this->quantityMath->compare($remaining, '0') > 0) {
                    return true;
                }
            } catch (LocalizedException $exception) {
                continue;
            }
        }

        return false;
    }
}
