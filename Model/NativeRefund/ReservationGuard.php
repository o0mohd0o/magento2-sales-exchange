<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\NativeRefund;

use Bonlineco\SalesExchange\Api\RemainingQuantityCalculatorInterface;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\CanonicalRefundedQuantity;
use Bonlineco\SalesExchange\Model\Creditmemo\ExecutionContext;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Bonlineco\SalesExchange\Model\Order\FreshOrderLoader;
use Bonlineco\SalesExchange\Model\ResourceModel\AllocationGuard;
use Bonlineco\SalesExchange\Model\ResourceModel\ReturnItem as ReturnItemResource;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;

/**
 * Protect quantity and refundable financial capacity reserved by an exchange.
 */
class ReservationGuard
{
    private FreshOrderLoader $freshOrderLoader;

    private AllocationGuard $allocationGuard;

    private ReturnItemResource $returnItemResource;

    private RemainingQuantityCalculatorInterface $remainingQuantityCalculator;

    /**
     * Money arithmetic uses the native sales-column precision.
     *
     * @var DecimalMath
     */
    private DecimalMath $moneyMath;

    private DecimalMath $quantityMath;

    private CanonicalRefundedQuantity $canonicalRefundedQuantity;

    private ExecutionContext $executionContext;

    public function __construct(
        FreshOrderLoader $freshOrderLoader,
        AllocationGuard $allocationGuard,
        ReturnItemResource $returnItemResource,
        RemainingQuantityCalculatorInterface $remainingQuantityCalculator,
        DecimalMath $moneyMath,
        DecimalMath $quantityMath,
        CanonicalRefundedQuantity $canonicalRefundedQuantity,
        ExecutionContext $executionContext
    ) {
        $this->freshOrderLoader = $freshOrderLoader;
        $this->allocationGuard = $allocationGuard;
        $this->returnItemResource = $returnItemResource;
        $this->remainingQuantityCalculator = $remainingQuantityCalculator;
        $this->moneyMath = $moneyMath;
        $this->quantityMath = $quantityMath;
        $this->canonicalRefundedQuantity = $canonicalRefundedQuantity;
        $this->executionContext = $executionContext;
    }

    public function execute(
        CreditmemoInterface $creditmemo,
        OrderInterface $passedOrder
    ): void {
        if ($this->isTrustedExchangeRefund($creditmemo)) {
            return;
        }

        $orderId = (int)$creditmemo->getOrderId();
        if ($orderId <= 0 || (int)$passedOrder->getEntityId() !== $orderId) {
            throw new InvariantViolationException(
                __('The native credit memo does not match its original order.')
            );
        }

        $passedItems = $this->indexOrderItems($passedOrder, $orderId);
        $passedActualQuantities = $this->aggregateActualQuantities(
            $creditmemo,
            $passedItems
        );
        $passedGroups = $this->groupCanonicalQuantities(
            $passedActualQuantities,
            $passedItems
        );

        $canonicalIds = array_keys($passedGroups);
        sort($canonicalIds, SORT_NUMERIC);
        $hasPositiveAdjustment = $this->hasPositiveAdjustment($creditmemo);
        $lockedOrderItemIds = $hasPositiveAdjustment
            ? array_keys($passedItems)
            : $canonicalIds;
        sort($lockedOrderItemIds, SORT_NUMERIC);
        foreach ($lockedOrderItemIds as $orderItemId) {
            $this->allocationGuard->lock($orderItemId);
        }

        if ($hasPositiveAdjustment) {
            // This is the first consistent read after every order-item
            // allocation row is locked. The outer order mutex prevents a
            // supported allocation increase after this snapshot begins.
            $this->assertFinancialCapacity(
                $this->returnItemResource->getAllocatedQuantityForOrder($orderId)
            );
        }

        // A positive-adjustment path shares the post-lock allocation snapshot;
        // ordinary quantity-only refunds start their snapshot here.
        $freshOrder = $this->freshOrderLoader->execute($orderId);
        $freshItems = $this->indexOrderItems($freshOrder, $orderId);
        if ($hasPositiveAdjustment) {
            $freshOrderItemIds = array_keys($freshItems);
            sort($freshOrderItemIds, SORT_NUMERIC);
            if ($lockedOrderItemIds !== $freshOrderItemIds) {
                throw new InvariantViolationException(
                    __('The native refund order-item set changed while locking.')
                );
            }
        }
        $actualQuantities = $this->aggregateActualQuantities(
            $creditmemo,
            $freshItems
        );
        $canonicalGroups = $this->groupCanonicalQuantities(
            $actualQuantities,
            $freshItems
        );
        $canonicalQuantities = $this->collapseCanonicalQuantities($canonicalGroups);

        $freshCanonicalIds = array_keys($canonicalQuantities);
        sort($freshCanonicalIds, SORT_NUMERIC);
        if ($canonicalIds !== $freshCanonicalIds
            || $this->getCanonicalMapping($passedGroups)
                !== $this->getCanonicalMapping($canonicalGroups)
        ) {
            throw new InvariantViolationException(
                __('The native refund order-item mapping changed while locking.')
            );
        }

        $this->assertSupportedConfigurableGroups(
            $canonicalGroups,
            $freshItems
        );
        $this->assertFreshSnapshots($canonicalGroups, $freshItems, $passedItems);
        foreach ($canonicalIds as $canonicalId) {
            $orderItem = $freshItems[$canonicalId];
            $remaining = $this->remainingQuantityCalculator->execute(
                (string)$orderItem->getQtyInvoiced(),
                $this->canonicalRefundedQuantity->execute(
                    $orderItem,
                    $freshItems
                ),
                $this->returnItemResource->getAllocatedQuantity($canonicalId)
            );
            if ($this->quantityMath->compare(
                $canonicalQuantities[$canonicalId],
                $remaining
            ) > 0) {
                throw new InvariantViolationException(
                    __(
                        'The native refund quantity conflicts with an active '
                        . 'exchange reservation for order item "%1".',
                        $canonicalId
                    )
                );
            }
        }
    }

    /**
     * Determine whether either currency carries a positive manual adjustment.
     *
     * @param CreditmemoInterface $creditmemo
     * @return bool
     */
    private function hasPositiveAdjustment(CreditmemoInterface $creditmemo): bool
    {
        foreach ([
            $creditmemo->getAdjustmentPositive(),
            $creditmemo->getBaseAdjustmentPositive(),
        ] as $adjustment) {
            $amount = $adjustment === null || $adjustment === ''
                ? '0.0000'
                : (string)$adjustment;
            if ($this->moneyMath->compare($amount, '0') > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reject a positive adjustment while exchange quantity remains reserved.
     *
     * @param string $orderAllocatedQuantity
     * @return void
     */
    private function assertFinancialCapacity(string $orderAllocatedQuantity): void
    {
        $orderAllocatedQuantity = $this->quantityMath->assertNonNegative(
            $orderAllocatedQuantity,
            'Active exchange allocation'
        );
        if ($this->quantityMath->compare($orderAllocatedQuantity, '0') > 0) {
            throw new InvariantViolationException(
                __(
                    'A positive native refund adjustment cannot consume '
                    . 'financial capacity reserved by an active exchange.'
                )
            );
        }
    }

    private function isTrustedExchangeRefund(CreditmemoInterface $creditmemo): bool
    {
        $marker = $this->executionContext->readCreditmemoMarker($creditmemo);

        return $marker !== null && $this->executionContext->isActiveFor($marker);
    }

    /**
     * @return array<int, OrderItemInterface>
     */
    private function indexOrderItems(OrderInterface $order, int $orderId): array
    {
        if ((int)$order->getEntityId() !== $orderId) {
            throw new InvariantViolationException(
                __('The native refund order snapshot is invalid.')
            );
        }

        $indexed = [];
        foreach ((array)$order->getItems() as $orderItem) {
            if (!$orderItem instanceof OrderItemInterface) {
                continue;
            }
            $orderItemId = (int)$orderItem->getItemId();
            if ($orderItemId <= 0 || (int)$orderItem->getOrderId() !== $orderId) {
                throw new InvariantViolationException(
                    __('The native refund order contains an invalid item snapshot.')
                );
            }
            $indexed[$orderItemId] = $orderItem;
        }

        return $indexed;
    }

    /**
     * Aggregate duplicate credit-memo rows by their actual order-item ID.
     *
     * @param array<int, OrderItemInterface> $freshItems
     * @return array<int, string>
     */
    private function aggregateActualQuantities(
        CreditmemoInterface $creditmemo,
        array $freshItems
    ): array {
        $quantities = [];
        foreach ((array)$creditmemo->getItems() as $creditmemoItem) {
            $quantity = $this->quantityMath->assertNonNegative(
                (string)$creditmemoItem->getQty(),
                'Native credit memo item quantity'
            );
            if ($this->quantityMath->compare($quantity, '0') === 0) {
                continue;
            }

            $orderItemId = (int)$creditmemoItem->getOrderItemId();
            if (!isset($freshItems[$orderItemId])) {
                throw new InvariantViolationException(
                    __('The native credit memo contains an unrelated order item.')
                );
            }
            $quantities[$orderItemId] = isset($quantities[$orderItemId])
                ? $this->quantityMath->add($quantities[$orderItemId], $quantity)
                : $quantity;
        }

        return $quantities;
    }

    /**
     * Map configurable children to their visible parent reservation.
     *
     * @param array<int, string> $actualQuantities
     * @param array<int, OrderItemInterface> $freshItems
     * @return array<int, array<int, string>>
     */
    private function groupCanonicalQuantities(
        array $actualQuantities,
        array $freshItems
    ): array {
        $groups = [];
        foreach ($actualQuantities as $actualId => $quantity) {
            $canonicalId = $this->getCanonicalOrderItemId(
                $freshItems[$actualId],
                $freshItems
            );
            $groups[$canonicalId][$actualId] = $quantity;
        }

        return $groups;
    }

    /**
     * @param array<int, array<int, string>> $groups
     * @return array<int, int>
     */
    private function getCanonicalMapping(array $groups): array
    {
        $mapping = [];
        foreach ($groups as $canonicalId => $actualQuantities) {
            foreach (array_keys($actualQuantities) as $actualId) {
                $mapping[$actualId] = $canonicalId;
            }
        }
        ksort($mapping, SORT_NUMERIC);

        return $mapping;
    }

    /**
     * Only Magento's matching positive parent/generated-child representation
     * is safe. Alternating crafted one-sided refunds cannot be reconstructed
     * from Magento's parent and dummy-child aggregate counters.
     *
     * @param array<int, array<int, string>> $groups
     * @param array<int, OrderItemInterface> $freshItems
     */
    private function assertSupportedConfigurableGroups(
        array $groups,
        array $freshItems
    ): void {
        foreach ($groups as $canonicalId => $actualQuantities) {
            if ((string)$freshItems[$canonicalId]->getProductType()
                !== 'configurable'
            ) {
                continue;
            }
            if (!isset($actualQuantities[$canonicalId])
                || count($actualQuantities) !== 2
            ) {
                throw new InvariantViolationException(
                    __(
                        'A configurable refund requires matching positive '
                        . 'parent and generated-child quantities.'
                    )
                );
            }
        }
    }

    /**
     * Count a normal configurable parent plus generated child only once.
     *
     * One-sided shapes are still canonicalized here so their allocation rows
     * are locked, then rejected by the configurable-shape assertion.
     *
     * @param array<int, array<int, string>> $groups
     * @return array<int, string>
     */
    private function collapseCanonicalQuantities(array $groups): array
    {
        $collapsed = [];
        foreach ($groups as $canonicalId => $actualQuantities) {
            if (isset($actualQuantities[$canonicalId])) {
                $canonicalQuantity = $actualQuantities[$canonicalId];
                foreach ($actualQuantities as $actualId => $quantity) {
                    if ($actualId !== $canonicalId
                        && $this->quantityMath->compare(
                            $quantity,
                            $canonicalQuantity
                        ) !== 0
                    ) {
                        throw new InvariantViolationException(
                            __(
                                'Configurable parent and child refund quantities '
                                . 'must match.'
                            )
                        );
                    }
                }
                $collapsed[$canonicalId] = $canonicalQuantity;
                continue;
            }

            $canonicalQuantity = '0.0000';
            foreach ($actualQuantities as $quantity) {
                $canonicalQuantity = $this->quantityMath->add(
                    $canonicalQuantity,
                    $quantity
                );
            }
            $collapsed[$canonicalId] = $canonicalQuantity;
        }

        return $collapsed;
    }

    /**
     * @param array<int, OrderItemInterface> $freshItems
     */
    private function getCanonicalOrderItemId(
        OrderItemInterface $orderItem,
        array $freshItems
    ): int {
        $orderItemId = (int)$orderItem->getItemId();
        $parentId = (int)$orderItem->getParentItemId();
        if ($parentId > 0
            && isset($freshItems[$parentId])
            && (string)$freshItems[$parentId]->getProductType() === 'configurable'
        ) {
            return $parentId;
        }

        return $orderItemId;
    }

    /**
     * @param array<int, array<int, string>> $groups
     * @param array<int, OrderItemInterface> $freshItems
     * @param array<int, OrderItemInterface> $passedItems
     */
    private function assertFreshSnapshots(
        array $groups,
        array $freshItems,
        array $passedItems
    ): void {
        $relevantIds = [];
        foreach ($groups as $canonicalId => $actualQuantities) {
            $relevantIds[$canonicalId] = true;
            foreach (array_keys($actualQuantities) as $actualId) {
                $relevantIds[$actualId] = true;
            }
        }
        $itemIds = array_keys($relevantIds);
        sort($itemIds, SORT_NUMERIC);

        foreach ($itemIds as $itemId) {
            if (!isset($freshItems[$itemId], $passedItems[$itemId])) {
                throw new InvariantViolationException(
                    __('The native refund order item snapshot is incomplete.')
                );
            }
            foreach ([
                [$freshItems[$itemId]->getQtyInvoiced(), $passedItems[$itemId]->getQtyInvoiced()],
                [$freshItems[$itemId]->getQtyRefunded(), $passedItems[$itemId]->getQtyRefunded()],
            ] as [$freshQuantity, $passedQuantity]) {
                if ($this->quantityMath->compare(
                    (string)$freshQuantity,
                    (string)$passedQuantity
                ) !== 0) {
                    throw new InvariantViolationException(
                        __(
                            'The native refund used a stale invoiced or refunded '
                            . 'quantity for order item "%1".',
                            $itemId
                        )
                    );
                }
            }
        }
    }
}
