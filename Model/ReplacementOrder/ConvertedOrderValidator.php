<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\ReplacementOrder;

use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Magento\Framework\Model\AbstractModel;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;

/**
 * Prove Magento converted the final trusted quote without commercial drift.
 */
class ConvertedOrderValidator
{
    private DecimalMath $moneyMath;

    private DecimalMath $quantityMath;

    public function __construct(
        DecimalMath $moneyMath,
        DecimalMath $quantityMath
    ) {
        $this->moneyMath = $moneyMath;
        $this->quantityMath = $quantityMath;
    }

    public function execute(Quote $quote, OrderInterface $order): void
    {
        $this->assertIdentityAndTotals($quote, $order);
        $quoteItems = $this->indexQuoteItems($quote);
        $orderItems = $order->getItems();
        if (!is_array($orderItems)
            || count($orderItems) !== count($quoteItems)
        ) {
            throw new InvariantViolationException(
                __('Magento converted a different replacement item set.')
            );
        }

        $seen = [];
        foreach ($orderItems as $orderItem) {
            if (!$orderItem instanceof OrderItemInterface
                || !$orderItem instanceof AbstractModel
            ) {
                throw new InvariantViolationException(
                    __('A converted replacement order item is invalid.')
                );
            }
            $replacementItemId = (int)$orderItem->getData(
                Marker::REPLACEMENT_ITEM_ID
            );
            if (!isset($quoteItems[$replacementItemId])
                || isset($seen[$replacementItemId])
            ) {
                throw new InvariantViolationException(
                    __('A converted replacement order marker is invalid.')
                );
            }
            $seen[$replacementItemId] = true;
            $this->assertItem(
                $quoteItems[$replacementItemId],
                $orderItem
            );
        }
        if (count($seen) !== count($quoteItems)) {
            throw new InvariantViolationException(
                __('Magento omitted a converted replacement order item.')
            );
        }
    }

    private function assertIdentityAndTotals(
        Quote $quote,
        OrderInterface $order
    ): void {
        $address = $quote->getShippingAddress();
        $quotePayment = $quote->getPayment();
        $orderPayment = $order->getPayment();
        $matches = $order instanceof AbstractModel
            && (int)$order->getQuoteId() === (int)$quote->getId()
            && (int)$order->getStoreId() === (int)$quote->getStoreId()
            && (int)$quote->getData(Marker::EXCHANGE_ID) > 0
            && (int)$order->getData(Marker::EXCHANGE_ID)
                === (int)$quote->getData(Marker::EXCHANGE_ID)
            && $this->sameIntentHash(
                $order->getData(Marker::INTENT_HASH),
                $quote->getData(Marker::INTENT_HASH)
            )
            && $this->nullableInt($order->getCustomerId())
                === $this->nullableInt($quote->getCustomerId())
            && (string)$order->getOrderCurrencyCode()
                === (string)$quote->getQuoteCurrencyCode()
            && (string)$order->getBaseCurrencyCode()
                === (string)$quote->getBaseCurrencyCode()
            && (string)$order->getCustomerEmail()
                === (string)$quote->getCustomerEmail()
            && trim((string)$order->getCouponCode()) === ''
            && (string)$order->getShippingMethod()
                === (string)$address->getShippingMethod()
            && $quotePayment !== null
            && $orderPayment !== null
            && (string)$orderPayment->getMethod()
                === (string)$quotePayment->getMethod()
            && $this->sameMoney(
                $order->getSubtotal(),
                $quote->getSubtotal()
            )
            && $this->sameMoney(
                $order->getBaseSubtotal(),
                $quote->getBaseSubtotal()
            )
            && $this->sameMoney(
                $order->getTaxAmount(),
                $address->getTaxAmount()
            )
            && $this->sameMoney(
                $order->getBaseTaxAmount(),
                $address->getBaseTaxAmount()
            )
            && $this->sameMoney(
                $order->getGrandTotal(),
                $quote->getGrandTotal()
            )
            && $this->sameMoney(
                $order->getBaseGrandTotal(),
                $quote->getBaseGrandTotal()
            )
            && $this->sameMoney(
                $order->getShippingAmount(),
                $address->getShippingAmount()
            )
            && $this->sameMoney(
                $order->getBaseShippingAmount(),
                $address->getBaseShippingAmount()
            )
            && $this->sameMoney(
                $order->getDiscountAmount(),
                $address->getDiscountAmount()
            )
            && $this->sameMoney(
                $order->getBaseDiscountAmount(),
                $address->getBaseDiscountAmount()
            );
        if (!$matches) {
            throw new InvariantViolationException(
                __('Magento converted different replacement order totals.')
            );
        }
    }

    /**
     * @return array<int, Item>
     */
    private function indexQuoteItems(Quote $quote): array
    {
        $indexed = [];
        foreach ($quote->getAllVisibleItems() as $item) {
            if (!$item instanceof Item) {
                throw new InvariantViolationException(
                    __('A trusted replacement quote item is invalid.')
                );
            }
            $replacementItemId = (int)$item->getData(
                Marker::REPLACEMENT_ITEM_ID
            );
            if ($replacementItemId <= 0
                || isset($indexed[$replacementItemId])
            ) {
                throw new InvariantViolationException(
                    __('A trusted replacement quote marker is invalid.')
                );
            }
            $indexed[$replacementItemId] = $item;
        }
        if ($indexed === []) {
            throw new InvariantViolationException(
                __('The trusted replacement quote has no items.')
            );
        }

        return $indexed;
    }

    private function assertItem(
        Item $quoteItem,
        OrderItemInterface $orderItem
    ): void {
        $matches = $orderItem->getParentItemId() === null
            && (int)$orderItem->getProductId()
                === (int)$quoteItem->getProductId()
            && (string)$orderItem->getSku()
                === (string)$quoteItem->getSku()
            && (string)$orderItem->getName()
                === (string)$quoteItem->getName()
            && (string)$orderItem->getProductType()
                === (string)$quoteItem->getData('product_type')
            && $this->quantityMath->compare(
                (string)$orderItem->getQtyOrdered(),
                (string)$quoteItem->getQty()
            ) === 0
            && $this->sameMoney(
                $orderItem->getPrice(),
                $quoteItem->getData('calculation_price')
            )
            && $this->sameMoney(
                $orderItem->getBasePrice(),
                $quoteItem->getData('base_price')
            )
            && $this->sameMoney(
                $orderItem->getRowTotal(),
                $quoteItem->getRowTotal()
            )
            && $this->sameMoney(
                $orderItem->getBaseRowTotal(),
                $quoteItem->getBaseRowTotal()
            )
            && $this->sameMoney(
                $orderItem->getData('price_incl_tax'),
                $quoteItem->getData('price_incl_tax')
            )
            && $this->sameMoney(
                $orderItem->getData('base_price_incl_tax'),
                $quoteItem->getData('base_price_incl_tax')
            )
            && $this->sameMoney(
                $orderItem->getData('row_total_incl_tax'),
                $quoteItem->getData('row_total_incl_tax')
            )
            && $this->sameMoney(
                $orderItem->getData('base_row_total_incl_tax'),
                $quoteItem->getData('base_row_total_incl_tax')
            )
            && $this->sameMoney(
                $orderItem->getTaxAmount(),
                $quoteItem->getTaxAmount()
            )
            && $this->sameMoney(
                $orderItem->getBaseTaxAmount(),
                $quoteItem->getBaseTaxAmount()
            )
            && $this->sameMoney(
                $orderItem->getDiscountAmount(),
                $quoteItem->getDiscountAmount()
            )
            && $this->sameMoney(
                $orderItem->getBaseDiscountAmount(),
                $quoteItem->getBaseDiscountAmount()
            );
        if (!$matches) {
            throw new InvariantViolationException(
                __('Magento converted a replacement order item with drift.')
            );
        }
    }

    /**
     * @param mixed $left
     * @param mixed $right
     */
    private function sameMoney($left, $right): bool
    {
        return $this->moneyMath->compare(
            (string)$left,
            (string)$right
        ) === 0;
    }

    /**
     * @param mixed $value
     */
    private function nullableInt($value): ?int
    {
        return $value === null ? null : (int)$value;
    }

    /**
     * @param mixed $left
     * @param mixed $right
     */
    private function sameIntentHash($left, $right): bool
    {
        return is_string($left)
            && is_string($right)
            && preg_match('/^[a-f0-9]{64}$/D', $left) === 1
            && hash_equals($left, $right);
    }
}
