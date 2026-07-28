<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\ReplacementOrder;

use Bonlineco\SalesExchange\Api\Data\ExchangeInterface;
use Bonlineco\SalesExchange\Api\Data\ReplacementItemInterface;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Carrier\Replacement as ReplacementCarrier;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Bonlineco\SalesExchange\Model\Payment\Replacement as ReplacementPayment;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Fail closed unless core quote totals exactly represent the frozen intent.
 */
class QuoteValidator
{
    private ProductRepositoryInterface $productRepository;

    private AddressSnapshotCopier $addressSnapshotCopier;

    private DecimalMath $moneyMath;

    private DecimalMath $quantityMath;

    public function __construct(
        ProductRepositoryInterface $productRepository,
        AddressSnapshotCopier $addressSnapshotCopier,
        DecimalMath $moneyMath,
        DecimalMath $quantityMath
    ) {
        $this->productRepository = $productRepository;
        $this->addressSnapshotCopier = $addressSnapshotCopier;
        $this->moneyMath = $moneyMath;
        $this->quantityMath = $quantityMath;
    }

    /**
     * @param array<int, array<string, mixed>> $replacementRows
     */
    public function assertPrepared(
        Quote $quote,
        OrderInterface $originalOrder,
        ExchangeInterface $exchange,
        array $replacementRows,
        string $intentHash
    ): void {
        $this->assertQuoteIdentity(
            $quote,
            $originalOrder,
            $exchange,
            $intentHash
        );
        $rows = $this->indexRows($replacementRows);
        $this->assertItems($quote, $rows, $exchange);
        $this->assertTotals($quote, $exchange);
    }

    private function assertQuoteIdentity(
        Quote $quote,
        OrderInterface $originalOrder,
        ExchangeInterface $exchange,
        string $intentHash
    ): void {
        if (!preg_match('/^[a-f0-9]{64}$/D', $intentHash)
            || (int)$quote->getData(Marker::EXCHANGE_ID)
                !== $exchange->getEntityId()
            || !is_string($quote->getData(Marker::INTENT_HASH))
            || !hash_equals(
                $intentHash,
                (string)$quote->getData(Marker::INTENT_HASH)
            )
        ) {
            throw new InvariantViolationException(
                __('The prepared quote markers do not match the replacement intent.')
            );
        }
        if ((bool)$quote->getIsActive()
            || $quote->getOrigOrderId() !== null
            || (int)$quote->getStoreId() !== $exchange->getStoreId()
            || (int)$originalOrder->getEntityId()
                !== $exchange->getOriginalOrderId()
            || (int)$originalOrder->getStoreId() !== $exchange->getStoreId()
            || (string)$originalOrder->getOrderCurrencyCode()
                !== $exchange->getCurrencyCode()
            || (string)$originalOrder->getBaseCurrencyCode()
                !== $exchange->getBaseCurrencyCode()
            || (string)$quote->getQuoteCurrencyCode()
                !== $exchange->getCurrencyCode()
            || (string)$quote->getBaseCurrencyCode()
                !== $exchange->getBaseCurrencyCode()
        ) {
            throw new InvariantViolationException(
                __('The prepared quote does not preserve the original order snapshots.')
            );
        }
        $orderCustomerId = $originalOrder->getCustomerId();
        $normalizedOrderCustomerId = $orderCustomerId === null
            ? null
            : (int)$orderCustomerId;
        $quoteCustomerId = $quote->getCustomerId();
        $normalizedQuoteCustomerId = $quoteCustomerId === null
            ? null
            : (int)$quoteCustomerId;
        if ($normalizedOrderCustomerId !== $exchange->getCustomerId()
            || $normalizedQuoteCustomerId !== $exchange->getCustomerId()
            || (bool)$quote->getCustomerIsGuest()
                !== (bool)$originalOrder->getCustomerIsGuest()
            || trim((string)$quote->getCustomerEmail()) === ''
        ) {
            throw new InvariantViolationException(
                __('The prepared quote customer snapshot is inconsistent.')
            );
        }
        if (trim((string)$quote->getCouponCode()) !== ''
            || trim((string)$quote->getAppliedRuleIds()) !== ''
        ) {
            throw new InvariantViolationException(
                __('Coupons and sales rules are not allowed on a replacement quote.')
            );
        }
        $this->addressSnapshotCopier->assertMatches(
            $originalOrder,
            $quote
        );
        $expectedShippingMethod = ReplacementCarrier::CARRIER_CODE
            . '_'
            . ReplacementCarrier::METHOD_CODE;
        if ((string)$quote->getShippingAddress()->getShippingMethod()
                !== $expectedShippingMethod
            || (string)$quote->getPayment()->getMethod()
                !== ReplacementPayment::CODE
        ) {
            throw new InvariantViolationException(
                __('The prepared quote must use the trusted replacement delivery and payment methods.')
            );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $replacementRows
     * @return array<int, array<string, mixed>>
     */
    private function indexRows(array $replacementRows): array
    {
        $indexed = [];
        foreach ($replacementRows as $row) {
            $itemId = (int)($row[ReplacementItemInterface::ENTITY_ID] ?? 0);
            if ($itemId <= 0 || isset($indexed[$itemId])) {
                throw new InvariantViolationException(
                    __('The frozen replacement item set is invalid.')
                );
            }
            $indexed[$itemId] = $row;
        }

        return $indexed;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function assertItems(
        Quote $quote,
        array $rows,
        ExchangeInterface $exchange
    ): void {
        $seen = [];
        $calculatedSubtotal = '0.0000';
        $items = $quote->getAllVisibleItems();
        if (count($items) !== count($rows)) {
            throw new InvariantViolationException(
                __('The prepared quote item count does not match the frozen replacement.')
            );
        }
        foreach ($items as $item) {
            if (!$item instanceof Item) {
                throw new InvariantViolationException(
                    __('The prepared quote item implementation is not supported.')
                );
            }
            $replacementItemId = (int)$item->getData(
                Marker::REPLACEMENT_ITEM_ID
            );
            if (!isset($rows[$replacementItemId])
                || isset($seen[$replacementItemId])
            ) {
                throw new InvariantViolationException(
                    __('A prepared quote item has an invalid replacement marker.')
                );
            }
            $seen[$replacementItemId] = true;
            $row = $rows[$replacementItemId];
            $this->assertItemSnapshot(
                $item,
                $row,
                (int)$exchange->getStoreId()
            );
            $rowTotal = $this->moneyMath->normalize(
                (string)$item->getRowTotal()
            );
            if ($this->moneyMath->compare(
                $rowTotal,
                (string)$row[ReplacementItemInterface::ROW_TOTAL_AMOUNT]
            ) !== 0) {
                throw new InvariantViolationException(
                    __('A prepared quote row total drifted from its approved amount.')
                );
            }
            $calculatedSubtotal = $this->moneyMath->add(
                $calculatedSubtotal,
                $rowTotal
            );
        }
        if (count($seen) !== count($rows)
            || $this->moneyMath->compare(
                $calculatedSubtotal,
                $exchange->getReplacementAmount()
            ) !== 0
        ) {
            throw new InvariantViolationException(
                __('The prepared quote does not represent every frozen replacement row.')
            );
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function assertItemSnapshot(
        Item $item,
        array $row,
        int $storeId
    ): void {
        $productId = (int)$row[ReplacementItemInterface::PRODUCT_ID];
        $product = $this->productRepository->getById(
            $productId,
            false,
            $storeId,
            true
        );
        if (!$product instanceof Product
            || (int)$product->getId() !== $productId
            || (string)$product->getSku()
                !== (string)$row[ReplacementItemInterface::SKU]
            || (int)$product->getStatus() !== Status::STATUS_ENABLED
            || (string)$product->getTypeId() !== Type::TYPE_SIMPLE
            || $product->getIsVirtual()
            || $product->getHasOptions()
            || !$product->isSalable()
        ) {
            throw new InvariantViolationException(
                __('Replacement SKU "%1" is no longer an enabled, salable, physical simple product.', $item->getSku())
            );
        }
        $matches = (int)$item->getProductId() === $productId
            && (string)$item->getSku()
                === (string)$row[ReplacementItemInterface::SKU]
            && (string)$item->getName()
                === (string)$row[ReplacementItemInterface::NAME]
            && (string)$item->getProductType() === Type::TYPE_SIMPLE
            && !$item->getIsVirtual()
            && (bool)$item->getNoDiscount()
            && trim((string)$item->getAppliedRuleIds()) === ''
            && $this->quantityMath->compare(
                (string)$item->getQty(),
                (string)$row[ReplacementItemInterface::QTY]
            ) === 0
            && $this->moneyMath->compare(
                (string)$item->getCustomPrice(),
                (string)$row[ReplacementItemInterface::UNIT_PRICE_AMOUNT]
            ) === 0
            && $this->moneyMath->compare(
                (string)$item->getOriginalCustomPrice(),
                (string)$row[ReplacementItemInterface::UNIT_PRICE_AMOUNT]
            ) === 0
            && $this->moneyMath->compare(
                (string)$item->getDiscountAmount(),
                '0'
            ) === 0
            && $this->moneyMath->compare(
                (string)$item->getBaseDiscountAmount(),
                '0'
            ) === 0;
        if (!$matches) {
            throw new InvariantViolationException(
                __('A prepared quote item drifted from its frozen replacement snapshot.')
            );
        }
    }

    private function assertTotals(
        Quote $quote,
        ExchangeInterface $exchange
    ): void {
        $address = $quote->getShippingAddress();
        $subtotal = $this->moneyMath->assertNonNegative(
            (string)$quote->getSubtotal(),
            'Replacement quote subtotal'
        );
        $baseSubtotal = $this->moneyMath->assertNonNegative(
            (string)$quote->getBaseSubtotal(),
            'Replacement quote base subtotal'
        );
        $tax = $this->moneyMath->assertNonNegative(
            (string)$address->getTaxAmount(),
            'Replacement quote tax'
        );
        $baseTax = $this->moneyMath->assertNonNegative(
            (string)$address->getBaseTaxAmount(),
            'Replacement quote base tax'
        );
        $expectedGrandTotal = $this->moneyMath->add($subtotal, $tax);
        $expectedBaseGrandTotal = $this->moneyMath->add(
            $baseSubtotal,
            $baseTax
        );

        $matches = $this->moneyMath->compare(
            $subtotal,
            $exchange->getReplacementAmount()
        ) === 0
            && $this->moneyMath->compare(
                (string)$quote->getSubtotalWithDiscount(),
                $subtotal
            ) === 0
            && $this->moneyMath->compare(
                (string)$quote->getGrandTotal(),
                $expectedGrandTotal
            ) === 0
            && $this->moneyMath->compare(
                (string)$quote->getBaseGrandTotal(),
                $expectedBaseGrandTotal
            ) === 0
            && $this->moneyMath->compare(
                (string)$address->getGrandTotal(),
                $expectedGrandTotal
            ) === 0
            && $this->moneyMath->compare(
                (string)$address->getShippingAmount(),
                '0'
            ) === 0
            && $this->moneyMath->compare(
                (string)$address->getBaseShippingAmount(),
                '0'
            ) === 0
            && $this->moneyMath->compare(
                (string)$address->getShippingTaxAmount(),
                '0'
            ) === 0
            && $this->moneyMath->compare(
                (string)$address->getBaseShippingTaxAmount(),
                '0'
            ) === 0
            && $this->moneyMath->compare(
                (string)$address->getDiscountAmount(),
                '0'
            ) === 0
            && $this->moneyMath->compare(
                (string)$address->getBaseDiscountAmount(),
                '0'
            ) === 0
            && $this->moneyMath->compare(
                (string)$address->getShippingDiscountAmount(),
                '0'
            ) === 0
            && $this->moneyMath->compare(
                (string)$address->getBaseShippingDiscountAmount(),
                '0'
            ) === 0;
        if (!$matches) {
            throw new InvariantViolationException(
                __(
                    'The prepared quote contains a discount, shipping charge, '
                    . 'fee, or other total outside the approved replacement intent.'
                )
            );
        }
    }
}
