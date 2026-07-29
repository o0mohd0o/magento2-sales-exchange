<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Plugin;

use Bonlineco\SalesExchange\Api\Data\ReplacementItemInterface;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Bonlineco\SalesExchange\Model\ReplacementOrder\ExecutionContext;
use Bonlineco\SalesExchange\Model\ReplacementOrder\Marker;
use Magento\Catalog\Model\Product;
use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\Total;
use Magento\Quote\Model\Quote\Address\Total\Subtotal;
use Magento\Quote\Model\Quote\Item;

/**
 * Reapply server-frozen prices after third-party pre-collection observers.
 */
class RestoreReplacementQuotePricePlugin
{
    private ExecutionContext $executionContext;

    private DecimalMath $moneyMath;

    private DecimalMath $quantityMath;

    public function __construct(
        ExecutionContext $executionContext,
        DecimalMath $moneyMath,
        DecimalMath $quantityMath
    ) {
        $this->executionContext = $executionContext;
        $this->moneyMath = $moneyMath;
        $this->quantityMath = $quantityMath;
    }

    /**
     * Restore trusted item pricing immediately before Magento calculates rows.
     *
     * The server-created info_buyRequest is persisted with the quote item, so
     * this also protects a prepared quote after a repository reload. The
     * in-process execution context prevents ordinary or marker-spoofed carts
     * from reaching this path.
     *
     * @return null
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeCollect(
        Subtotal $subject,
        Quote $quote,
        ShippingAssignmentInterface $shippingAssignment,
        Total $total
    ): ?array {
        if (!$this->executionContext->isTrustedQuote($quote)) {
            return null;
        }

        $items = $quote->getAllVisibleItems();
        if ($items === []
            || count($items) !== $this->executionContext->getFrozenRowCount()
        ) {
            throw new InvariantViolationException(
                __('A trusted replacement quote has a different frozen item set.')
            );
        }
        $quote->setIsSuperMode(false);
        $seen = [];
        foreach ($items as $item) {
            $replacementItemId = $item instanceof Item
                ? (int)$item->getData(Marker::REPLACEMENT_ITEM_ID)
                : 0;
            $frozenRow = $this->executionContext
                ->getFrozenRow($replacementItemId);
            if (!$item instanceof Item
                || $replacementItemId <= 0
                || $frozenRow === null
                || isset($seen[$replacementItemId])
            ) {
                throw new InvariantViolationException(
                    __('A trusted replacement quote item has no valid frozen marker.')
                );
            }
            $seen[$replacementItemId] = true;
            $product = $item->getProduct();
            if (!$product instanceof Product
                || (int)$item->getProductId()
                    !== (int)$frozenRow[
                        ReplacementItemInterface::PRODUCT_ID
                    ]
                || (int)$product->getId()
                    !== (int)$frozenRow[
                        ReplacementItemInterface::PRODUCT_ID
                    ]
                || (string)$item->getSku()
                    !== (string)$frozenRow[ReplacementItemInterface::SKU]
                || (string)$product->getSku()
                    !== (string)$frozenRow[ReplacementItemInterface::SKU]
                || (string)$item->getName()
                    !== (string)$frozenRow[ReplacementItemInterface::NAME]
                || $this->quantityMath->compare(
                    (string)$item->getQty(),
                    (string)$frozenRow[ReplacementItemInterface::QTY]
                ) !== 0
            ) {
                throw new InvariantViolationException(
                    __('A trusted replacement quote item drifted from its frozen row.')
                );
            }
            $buyRequest = $item->getBuyRequest();
            $customPrice = $this->moneyMath->assertNonNegative(
                trim((string)$buyRequest->getData('custom_price')),
                'Frozen replacement custom price'
            );
            $originalCustomPrice = $this->moneyMath->assertNonNegative(
                trim((string)$buyRequest->getData('original_custom_price')),
                'Frozen replacement original custom price'
            );
            if ($this->moneyMath->compare(
                $customPrice,
                $originalCustomPrice
            ) !== 0
                || $this->moneyMath->compare(
                    $customPrice,
                    (string)$frozenRow[
                        ReplacementItemInterface::UNIT_PRICE_AMOUNT
                    ]
                ) !== 0
            ) {
                throw new InvariantViolationException(
                    __('A trusted replacement quote item has inconsistent frozen pricing.')
                );
            }

            $item->setCustomPrice($customPrice)
                ->setOriginalCustomPrice($originalCustomPrice)
                ->setTaxCalculationPrice(null)
                ->setBaseTaxCalculationPrice(null)
                ->setNoDiscount(true)
                ->setAppliedRuleIds(null);
            $product->setIsSuperMode(false);
        }
        if (count($seen) !== $this->executionContext->getFrozenRowCount()) {
            throw new InvariantViolationException(
                __('A trusted replacement quote omitted a frozen row.')
            );
        }

        return null;
    }
}
