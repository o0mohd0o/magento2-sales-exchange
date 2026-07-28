<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\Creditmemo;

use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Validate an exact native credit memo preview before side effects occur.
 */
class DocumentValidator
{
    private const HIGH_SCALE = 16;

    private const ROUNDING_TOLERANCE = '0.005000000001';

    private const BASE_COST_TOLERANCE = '0.000100000001';

    private DecimalMath $moneyMath;

    private DecimalMath $quantityMath;

    public function __construct(DecimalMath $moneyMath, DecimalMath $quantityMath)
    {
        $this->moneyMath = $moneyMath;
        $this->quantityMath = $quantityMath;
    }

    public function assertPreview(
        CreditmemoInterface $creditmemo,
        OrderInterface $order,
        string $currencyCode,
        string $baseCurrencyCode,
        string $expectedAmount,
        Plan $plan
    ): void {
        $this->assertDocument(
            $creditmemo,
            $order,
            $currencyCode,
            $baseCurrencyCode,
            $expectedAmount,
            $plan,
            true
        );
    }

    /**
     * Validate a saved document without comparing it to post-refund source remainders.
     */
    public function assertPersisted(
        CreditmemoInterface $creditmemo,
        OrderInterface $order,
        string $currencyCode,
        string $baseCurrencyCode,
        string $expectedAmount,
        Plan $plan
    ): void {
        $this->assertDocument(
            $creditmemo,
            $order,
            $currencyCode,
            $baseCurrencyCode,
            $expectedAmount,
            $plan,
            false
        );
    }

    /**
     * Capture the exact supported financial preview before native execution.
     *
     * @return array<string, mixed>
     */
    public function executionSnapshot(CreditmemoInterface $creditmemo): array
    {
        $items = [];
        foreach ($creditmemo->getItems() as $item) {
            $orderItemId = (int)$item->getOrderItemId();
            if ($orderItemId <= 0 || isset($items[$orderItemId])) {
                throw new InvariantViolationException(
                    __('A canonical credit memo snapshot requires unique order items.')
                );
            }
            $items[$orderItemId] = [
                'qty' => $this->quantityMath->normalize($this->component($item->getQty())),
                'row_total' => $this->snapshotMoney($item->getRowTotal()),
                'base_row_total' => $this->snapshotMoney($item->getBaseRowTotal()),
                'tax_amount' => $this->snapshotMoney($item->getTaxAmount()),
                'base_tax_amount' => $this->snapshotMoney($item->getBaseTaxAmount()),
                'discount_amount' => $this->snapshotMoney($item->getDiscountAmount()),
                'base_discount_amount' => $this->snapshotMoney(
                    $item->getBaseDiscountAmount()
                ),
                'discount_tax_compensation_amount' => $this->snapshotMoney(
                    $item->getDiscountTaxCompensationAmount()
                ),
                'base_discount_tax_compensation_amount' => $this->snapshotMoney(
                    $item->getBaseDiscountTaxCompensationAmount()
                ),
                'row_total_incl_tax' => $this->snapshotMoney(
                    $item->getRowTotalInclTax()
                ),
                'base_row_total_incl_tax' => $this->snapshotMoney(
                    $item->getBaseRowTotalInclTax()
                ),
                'base_cost' => $this->snapshotMoney($item->getBaseCost()),
                'back_to_stock' => $this->getItemDataFlag($item, 'back_to_stock'),
            ];
        }
        ksort($items, SORT_NUMERIC);

        return [
            'order_id' => (int)$creditmemo->getOrderId(),
            'invoice_id' => (int)$creditmemo->getInvoiceId(),
            'currency_code' => (string)$creditmemo->getOrderCurrencyCode(),
            'base_currency_code' => (string)$creditmemo->getBaseCurrencyCode(),
            'grand_total' => $this->snapshotMoney($creditmemo->getGrandTotal()),
            'base_grand_total' => $this->snapshotMoney($creditmemo->getBaseGrandTotal()),
            'subtotal' => $this->snapshotMoney($creditmemo->getSubtotal()),
            'base_subtotal' => $this->snapshotMoney($creditmemo->getBaseSubtotal()),
            'subtotal_incl_tax' => $this->snapshotMoney(
                $creditmemo->getSubtotalInclTax()
            ),
            'base_subtotal_incl_tax' => $this->snapshotMoney(
                $creditmemo->getBaseSubtotalInclTax()
            ),
            'tax_amount' => $this->snapshotMoney($creditmemo->getTaxAmount()),
            'base_tax_amount' => $this->snapshotMoney($creditmemo->getBaseTaxAmount()),
            'discount_amount' => $this->snapshotMoney($creditmemo->getDiscountAmount()),
            'base_discount_amount' => $this->snapshotMoney(
                $creditmemo->getBaseDiscountAmount()
            ),
            'discount_tax_compensation_amount' => $this->snapshotMoney(
                $creditmemo->getDiscountTaxCompensationAmount()
            ),
            'base_discount_tax_compensation_amount' => $this->snapshotMoney(
                $creditmemo->getBaseDiscountTaxCompensationAmount()
            ),
            'shipping_amount' => $this->snapshotMoney($creditmemo->getShippingAmount()),
            'base_shipping_amount' => $this->snapshotMoney(
                $creditmemo->getBaseShippingAmount()
            ),
            'shipping_tax_amount' => $this->snapshotMoney(
                $creditmemo->getShippingTaxAmount()
            ),
            'base_shipping_tax_amount' => $this->snapshotMoney(
                $creditmemo->getBaseShippingTaxAmount()
            ),
            'shipping_discount_tax_compensation_amount' => $this->snapshotMoney(
                $creditmemo->getShippingDiscountTaxCompensationAmount()
            ),
            'base_shipping_discount_tax_compensation_amount' => $this->snapshotMoney(
                $creditmemo->getBaseShippingDiscountTaxCompensationAmnt()
            ),
            'shipping_incl_tax' => $this->snapshotMoney(
                $creditmemo->getShippingInclTax()
            ),
            'base_shipping_incl_tax' => $this->snapshotMoney(
                $creditmemo->getBaseShippingInclTax()
            ),
            'adjustment' => $this->snapshotMoney($creditmemo->getAdjustment()),
            'base_adjustment' => $this->snapshotMoney(
                $creditmemo->getBaseAdjustment()
            ),
            'adjustment_positive' => $this->snapshotMoney(
                $creditmemo->getAdjustmentPositive()
            ),
            'base_adjustment_positive' => $this->snapshotMoney(
                $creditmemo->getBaseAdjustmentPositive()
            ),
            'adjustment_negative' => $this->snapshotMoney(
                $creditmemo->getAdjustmentNegative()
            ),
            'base_adjustment_negative' => $this->snapshotMoney(
                $creditmemo->getBaseAdjustmentNegative()
            ),
            'base_cost' => $this->normalizeHighPrecision(
                $this->getDocumentBaseCost($creditmemo) ?? '0'
            ),
            'items' => $items,
        ];
    }

    /**
     * Require the persisted document to be the exact validated preview.
     *
     * @param array<string, mixed> $expected
     */
    public function assertExecutionSnapshot(
        CreditmemoInterface $creditmemo,
        array $expected
    ): void {
        if ($this->executionSnapshot($creditmemo) !== $expected) {
            throw new InvariantViolationException(
                __(
                    'The persisted native credit memo differs from the canonical '
                    . 'validated preview.'
                )
            );
        }
    }

    public function persistentFingerprint(CreditmemoInterface $creditmemo): string
    {
        $snapshot = $this->executionSnapshot($creditmemo);
        unset($snapshot['base_cost']);
        foreach ($snapshot['items'] as $orderItemId => $item) {
            if ($this->quantityMath->compare($item['qty'], '0') === 0) {
                unset($snapshot['items'][$orderItemId]);
                continue;
            }
            unset($item['back_to_stock']);
            $snapshot['items'][$orderItemId] = $item;
        }

        return hash(
            'sha256',
            json_encode(
                $snapshot,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            )
        );
    }

    public function assertPersistentFingerprint(
        CreditmemoInterface $creditmemo,
        string $expectedFingerprint
    ): void {
        if (!preg_match('/^[a-f0-9]{64}$/D', $expectedFingerprint)
            || !hash_equals(
                $expectedFingerprint,
                $this->persistentFingerprint($creditmemo)
            )
        ) {
            throw new InvariantViolationException(
                __(
                    'The linked native credit memo differs from its persistent '
                    . 'canonical fingerprint.'
                )
            );
        }
    }

    private function assertDocument(
        CreditmemoInterface $creditmemo,
        OrderInterface $order,
        string $currencyCode,
        string $baseCurrencyCode,
        string $expectedAmount,
        Plan $plan,
        bool $validateSourceRemainders
    ): void {
        $snapshot = $this->snapshot(
            $creditmemo,
            (int)$order->getEntityId(),
            $currencyCode,
            $baseCurrencyCode
        );
        $this->moneyMath->assertNonNegative(
            $expectedAmount,
            'Approved credit memo amount'
        );
        foreach ([
            $creditmemo->getShippingAmount(),
            $creditmemo->getBaseShippingAmount(),
            $creditmemo->getShippingTaxAmount(),
            $creditmemo->getBaseShippingTaxAmount(),
            $creditmemo->getShippingDiscountTaxCompensationAmount(),
            $creditmemo->getBaseShippingDiscountTaxCompensationAmnt(),
            $creditmemo->getShippingInclTax(),
            $creditmemo->getBaseShippingInclTax(),
            $creditmemo->getAdjustment(),
            $creditmemo->getBaseAdjustment(),
            $creditmemo->getAdjustmentPositive(),
            $creditmemo->getAdjustmentNegative(),
            $creditmemo->getBaseAdjustmentPositive(),
            $creditmemo->getBaseAdjustmentNegative(),
        ] as $forbiddenAmount) {
            if ($this->moneyMath->compare($this->component($forbiddenAmount), '0') !== 0) {
                throw new InvariantViolationException(
                    __('Exchange credit memos cannot refund shipping or use manual adjustments.')
                );
            }
        }
        $this->assertQuantities($creditmemo, $order, $plan);
        if ($validateSourceRemainders) {
            $this->assertInventoryDisposition($creditmemo, $plan);
        }
        $isLast = $validateSourceRemainders
            ? $this->assertSourceRemainders($creditmemo, $order)
            : false;
        $this->assertDocumentItemTotals(
            $creditmemo,
            $validateSourceRemainders ? $order : null,
            $isLast
        );
        $this->assertCoreTotals($creditmemo, $snapshot);
    }

    /**
     * Validate identity and return canonical persisted totals.
     *
     * @return array{amount: string, base_amount: string}
     */
    public function snapshot(
        CreditmemoInterface $creditmemo,
        int $orderId,
        string $currencyCode,
        string $baseCurrencyCode
    ): array {
        if ((int)$creditmemo->getOrderId() !== $orderId) {
            throw new InvariantViolationException(
                __('The native credit memo does not belong to the exchange original order.')
            );
        }
        if ((string)$creditmemo->getOrderCurrencyCode() !== $currencyCode
            || (string)$creditmemo->getBaseCurrencyCode() !== $baseCurrencyCode
        ) {
            throw new InvariantViolationException(
                __('The native credit memo currencies do not match the exchange snapshots.')
            );
        }
        $amount = $this->moneyMath->assertNonNegative(
            (string)$creditmemo->getGrandTotal(),
            'Native credit memo total'
        );
        $baseAmount = $this->moneyMath->assertNonNegative(
            (string)$creditmemo->getBaseGrandTotal(),
            'Native base credit memo total'
        );
        return ['amount' => $amount, 'base_amount' => $baseAmount];
    }

    private function assertQuantities(
        CreditmemoInterface $creditmemo,
        OrderInterface $order,
        Plan $plan
    ): void
    {
        $plannedIds = array_keys($plan->getQuantitiesByOrderItem());
        $expected = $plan->getQuantitiesByOrderItem();
        $parentTypes = [];
        $childCounts = [];
        foreach ($order->getItems() as $orderItem) {
            $orderItemId = (int)$orderItem->getItemId();
            if (in_array($orderItemId, $plannedIds, true)) {
                $parentTypes[$orderItemId] = (string)$orderItem->getProductType();
            }
            $parentId = (int)$orderItem->getParentItemId();
            if (in_array($parentId, $plannedIds, true)) {
                $expected[$orderItemId] = $plan->getQuantitiesByOrderItem()[$parentId];
                $childCounts[$parentId] = ($childCounts[$parentId] ?? 0) + 1;
            }
        }
        foreach ($parentTypes as $orderItemId => $productType) {
            $childCount = $childCounts[$orderItemId] ?? 0;
            if (($productType === 'configurable' && $childCount !== 1)
                || ($productType === 'simple' && $childCount !== 0)
            ) {
                throw new InvariantViolationException(
                    __('The native credit memo preview has invalid configurable child semantics.')
                );
            }
        }
        $actual = [];
        foreach ($creditmemo->getItems() as $item) {
            $orderItemId = (int)$item->getOrderItemId();
            if ($orderItemId > 0) {
                $quantity = $this->quantityMath->normalize((string)$item->getQty());
                if (isset($actual[$orderItemId])) {
                    throw new InvariantViolationException(
                        __('The native credit memo preview duplicated an order item.')
                    );
                }
                if ($this->quantityMath->compare($quantity, '0') > 0
                    && !isset($expected[$orderItemId])
                ) {
                    throw new InvariantViolationException(
                        __('The native credit memo preview added an unrelated positive line.')
                    );
                }
                $actual[$orderItemId] = $quantity;
            }
        }
        foreach ($expected as $orderItemId => $quantity) {
            if (!isset($actual[$orderItemId])
                || $this->quantityMath->compare($actual[$orderItemId], $quantity) !== 0
            ) {
                throw new InvariantViolationException(
                    __('The native credit memo preview changed an accepted return quantity.')
                );
            }
        }
    }

    private function assertDocumentItemTotals(
        CreditmemoInterface $creditmemo,
        ?OrderInterface $sourceOrder,
        bool $isLast
    ): void {
        $totals = [
            'subtotal' => '0.0000',
            'base_subtotal' => '0.0000',
            'tax' => '0.0000',
            'base_tax' => '0.0000',
            'discount' => '0.0000',
            'base_discount' => '0.0000',
            'compensation' => '0.0000',
            'base_compensation' => '0.0000',
        ];
        foreach ($creditmemo->getItems() as $item) {
            $totals['subtotal'] = $this->addItemComponent($totals['subtotal'], $item->getRowTotal());
            $totals['base_subtotal'] = $this->addItemComponent(
                $totals['base_subtotal'],
                $item->getBaseRowTotal()
            );
            $totals['tax'] = $this->addItemComponent($totals['tax'], $item->getTaxAmount());
            $totals['base_tax'] = $this->addItemComponent(
                $totals['base_tax'],
                $item->getBaseTaxAmount()
            );
            $totals['discount'] = $this->addItemComponent(
                $totals['discount'],
                $item->getDiscountAmount()
            );
            $totals['base_discount'] = $this->addItemComponent(
                $totals['base_discount'],
                $item->getBaseDiscountAmount()
            );
            $totals['compensation'] = $this->addItemComponent(
                $totals['compensation'],
                $item->getDiscountTaxCompensationAmount()
            );
            $totals['base_compensation'] = $this->addItemComponent(
                $totals['base_compensation'],
                $item->getBaseDiscountTaxCompensationAmount()
            );
        }

        $exactItemExpected = [
            'subtotal' => $creditmemo->getSubtotal(),
            'base_subtotal' => $creditmemo->getBaseSubtotal(),
            'discount' => $this->negate($creditmemo->getDiscountAmount()),
            'base_discount' => $this->negate($creditmemo->getBaseDiscountAmount()),
        ];
        foreach ($exactItemExpected as $component => $documentValue) {
            if ($this->moneyMath->compare(
                $totals[$component],
                $this->component($documentValue)
            ) !== 0) {
                throw new InvariantViolationException(
                    __('Native credit memo totals must exactly equal their canonical item totals.')
                );
            }
        }

        $taxAndCompensation = [
            'tax' => $creditmemo->getTaxAmount(),
            'base_tax' => $creditmemo->getBaseTaxAmount(),
            'compensation' => $creditmemo->getDiscountTaxCompensationAmount(),
            'base_compensation' => $creditmemo->getBaseDiscountTaxCompensationAmount(),
        ];
        $matchesItemTotals = true;
        foreach ($taxAndCompensation as $component => $documentValue) {
            $documentAmount = $this->moneyMath->assertNonNegative(
                $this->component($documentValue),
                'Native credit memo tax component'
            );
            if ($this->moneyMath->compare($totals[$component], $documentAmount) !== 0) {
                $matchesItemTotals = false;
            }
        }
        if ($matchesItemTotals || $sourceOrder === null) {
            // A persisted document was already checked against its locked
            // pre-refund source; its immutable link preserves actual totals.
            return;
        }
        if (!$isLast || $this->hasRemainingShippingTaxOrCompensation($sourceOrder)) {
            throw new InvariantViolationException(
                __('Native credit memo tax totals must exactly equal their canonical item totals.')
            );
        }
        $orderRemainders = $this->getOrderTaxRemainders($sourceOrder);
        foreach ($taxAndCompensation as $component => $documentValue) {
            if ($this->moneyMath->compare(
                $this->component($documentValue),
                $orderRemainders[$component]
            ) !== 0) {
                throw new InvariantViolationException(
                    __(
                        'A final native credit memo tax delta must exactly equal '
                        . 'the locked order remainder.'
                    )
                );
            }
        }
    }

    private function assertInventoryDisposition(
        CreditmemoInterface $creditmemo,
        Plan $plan
    ): void {
        $returnToStock = array_fill_keys($plan->getReturnToStockOrderItemIds(), true);
        foreach ($creditmemo->getItems() as $item) {
            $orderItemId = (int)$item->getOrderItemId();
            $shouldReturn = isset($returnToStock[$orderItemId]);
            $actual = false;
            if (method_exists($item, 'getData')) {
                $actual = (bool)$item->getData('back_to_stock');
            } elseif (method_exists($item, 'getBackToStock')) {
                $actual = (bool)$item->getBackToStock();
            }
            if ($actual !== $shouldReturn) {
                throw new InvariantViolationException(
                    __(
                        'Native credit memo inventory disposition does not match '
                        . 'the finalized exchange inspection.'
                    )
                );
            }
        }
    }

    private function assertSourceRemainders(
        CreditmemoInterface $creditmemo,
        OrderInterface $order
    ): bool {
        $orderItems = [];
        foreach ($order->getItems() as $orderItem) {
            $orderItems[(int)$orderItem->getItemId()] = $orderItem;
        }
        $actualQuantities = [];
        $rawNet = ['order' => '0', 'base' => '0'];
        $actualNet = ['order' => '0', 'base' => '0'];
        $expectedBaseCost = '0';
        foreach ($creditmemo->getItems() as $item) {
            $orderItemId = (int)$item->getOrderItemId();
            if (!isset($orderItems[$orderItemId])) {
                throw new InvariantViolationException(
                    __('A native credit memo item has no matching live order item.')
                );
            }
            $orderItem = $orderItems[$orderItemId];
            $quantity = $this->quantityMath->assertNonNegative(
                $this->component($item->getQty()),
                'Native credit memo item quantity'
            );
            $quantityRemainder = $this->nonNegativeQuantityRemainder(
                $orderItem->getQtyInvoiced(),
                $orderItem->getQtyRefunded(),
                'Native refundable item quantity'
            );
            if ($this->quantityMath->compare($quantity, $quantityRemainder) > 0) {
                throw new InvariantViolationException(
                    __('A native credit memo item exceeds its live refundable quantity.')
                );
            }
            $actualQuantities[$orderItemId] = $quantity;

            $sourceBaseCost = $this->moneyMath->assertNonNegative(
                $this->component($orderItem->getBaseCost()),
                'Native refundable item base cost'
            );
            $itemBaseCost = $this->moneyMath->assertNonNegative(
                $this->component($item->getBaseCost()),
                'Native credit memo item base cost'
            );
            if ($this->moneyMath->compare($sourceBaseCost, $itemBaseCost) !== 0
                || $this->getItemDataFlag($item, 'has_children')
            ) {
                throw new InvariantViolationException(
                    __('A native credit memo item changed its locked base-cost snapshot.')
                );
            }
            $expectedBaseCost = bcadd(
                $expectedBaseCost,
                bcmul($sourceBaseCost, $quantity, self::HIGH_SCALE),
                self::HIGH_SCALE
            );

            $isFullRemainder = $this->quantityMath->compare(
                $quantity,
                $quantityRemainder
            ) === 0;
            $mustBeZero = $this->quantityMath->compare($quantity, '0') === 0
                || (int)$orderItem->getParentItemId() > 0;
            foreach ([
                [
                    $item->getRowTotal(),
                    $orderItem->getRowInvoiced(),
                    $orderItem->getAmountRefunded(),
                    'order',
                    1,
                ],
                [
                    $item->getBaseRowTotal(),
                    $orderItem->getBaseRowInvoiced(),
                    $orderItem->getBaseAmountRefunded(),
                    'base',
                    1,
                ],
                [
                    $item->getTaxAmount(),
                    $orderItem->getTaxInvoiced(),
                    $orderItem->getTaxRefunded(),
                    'order',
                    1,
                ],
                [
                    $item->getBaseTaxAmount(),
                    $orderItem->getBaseTaxInvoiced(),
                    $orderItem->getBaseTaxRefunded(),
                    'base',
                    1,
                ],
                [
                    $item->getDiscountAmount(),
                    $orderItem->getDiscountInvoiced(),
                    $orderItem->getDiscountRefunded(),
                    'order',
                    -1,
                ],
                [
                    $item->getBaseDiscountAmount(),
                    $orderItem->getBaseDiscountInvoiced(),
                    $orderItem->getBaseDiscountRefunded(),
                    'base',
                    -1,
                ],
                [
                    $item->getDiscountTaxCompensationAmount(),
                    $orderItem->getDiscountTaxCompensationInvoiced(),
                    $orderItem->getDiscountTaxCompensationRefunded(),
                    'order',
                    1,
                ],
                [
                    $item->getBaseDiscountTaxCompensationAmount(),
                    $orderItem->getBaseDiscountTaxCompensationInvoiced(),
                    $orderItem->getBaseDiscountTaxCompensationRefunded(),
                    'base',
                    1,
                ],
            ] as [$itemValue, $invoicedValue, $refundedValue, $currency, $sign]) {
                $component = $this->moneyMath->assertNonNegative(
                    $this->component($itemValue),
                    'Native credit memo item component'
                );
                $remainder = $this->nonNegativeRemainder(
                    $invoicedValue,
                    $refundedValue,
                    'Native refundable item component'
                );
                if ($mustBeZero) {
                    $canonical = '0';
                    $isCanonical = $this->moneyMath->compare($component, '0') === 0;
                } elseif ($isFullRemainder) {
                    $canonical = $remainder;
                    $isCanonical = $this->moneyMath->compare($component, $remainder) === 0;
                } else {
                    $canonical = $this->proportionalComponent(
                        $remainder,
                        $quantity,
                        $quantityRemainder
                    );
                    $isCanonical = $this->isCanonicalCentRounded($component, $canonical);
                }
                if (!$isCanonical) {
                    throw new InvariantViolationException(
                        __(
                            'A native credit memo item does not match its canonical '
                            . 'refundable component.'
                        )
                    );
                }
                $rawNet[$currency] = $this->addSignedHighPrecision(
                    $rawNet[$currency],
                    $canonical,
                    $sign
                );
                $actualNet[$currency] = $this->addSignedHighPrecision(
                    $actualNet[$currency],
                    $component,
                    $sign
                );
            }
        }

        foreach (['order', 'base'] as $currency) {
            if ($this->highPrecisionDifference(
                $actualNet[$currency],
                $rawNet[$currency]
            ) > 0) {
                throw new InvariantViolationException(
                    __(
                        'Native credit memo item rounding does not match Magento '
                        . 'delta-rounding semantics.'
                    )
                );
            }
        }
        $documentBaseCost = $this->getDocumentBaseCost($creditmemo);
        if ($documentBaseCost !== null
            && $this->highPrecisionDifference(
                $documentBaseCost,
                $expectedBaseCost,
                self::BASE_COST_TOLERANCE
            ) > 0
        ) {
            throw new InvariantViolationException(
                __('The native credit memo base cost does not match its canonical item total.')
            );
        }

        $isLast = true;
        foreach ($orderItems as $orderItemId => $orderItem) {
            if (method_exists($orderItem, 'isDummy') && $orderItem->isDummy()) {
                continue;
            }
            $quantityRemainder = $this->nonNegativeQuantityRemainder(
                $orderItem->getQtyInvoiced(),
                $orderItem->getQtyRefunded(),
                'Native refundable item quantity'
            );
            if ($this->quantityMath->compare($quantityRemainder, '0') > 0
                && (!isset($actualQuantities[$orderItemId])
                    || $this->quantityMath->compare(
                        $actualQuantities[$orderItemId],
                        $quantityRemainder
                    ) !== 0)
            ) {
                $isLast = false;
            }
        }

        return $isLast;
    }

    /**
     * @param array{amount: string, base_amount: string} $snapshot
     */
    private function assertCoreTotals(CreditmemoInterface $creditmemo, array $snapshot): void
    {
        $amount = $this->sumCoreComponents([
            $creditmemo->getSubtotal(),
            $creditmemo->getDiscountAmount(),
            $creditmemo->getShippingAmount(),
            $creditmemo->getTaxAmount(),
            $creditmemo->getDiscountTaxCompensationAmount(),
            $creditmemo->getAdjustmentPositive(),
            $this->negate($creditmemo->getAdjustmentNegative()),
        ]);
        $baseAmount = $this->sumCoreComponents([
            $creditmemo->getBaseSubtotal(),
            $creditmemo->getBaseDiscountAmount(),
            $creditmemo->getBaseShippingAmount(),
            $creditmemo->getBaseTaxAmount(),
            $creditmemo->getBaseDiscountTaxCompensationAmount(),
            $creditmemo->getBaseAdjustmentPositive(),
            $this->negate($creditmemo->getBaseAdjustmentNegative()),
        ]);
        if ($this->moneyMath->compare($amount, $snapshot['amount']) !== 0
            || $this->moneyMath->compare($baseAmount, $snapshot['base_amount']) !== 0
        ) {
            throw new InvariantViolationException(
                __(
                    'A built-in or additional credit memo total is not represented by '
                    . 'the supported structural fields. Install a compatible total '
                    . 'adapter before continuing.'
                )
            );
        }
    }

    /**
     * @param array<int, mixed> $components
     */
    private function sumCoreComponents(array $components): string
    {
        $total = '0.0000';
        foreach ($components as $component) {
            $total = $this->moneyMath->add($total, $this->component($component));
        }

        return $total;
    }

    /**
     * @param mixed $value
     */
    private function negate($value): string
    {
        $value = $this->component($value);
        return $this->moneyMath->subtract('0.0000', $value);
    }

    /**
     * @param mixed $value
     */
    private function component($value): string
    {
        return $value === null || $value === '' ? '0.0000' : (string)$value;
    }

    /**
     * @param mixed $value
     */
    private function snapshotMoney($value): string
    {
        return $this->moneyMath->normalize($this->component($value));
    }

    /**
     * @param mixed $value
     */
    private function addItemComponent(string $total, $value): string
    {
        return $this->moneyMath->add(
            $total,
            $this->moneyMath->assertNonNegative(
                $this->component($value),
                'Native credit memo item component'
            )
        );
    }

    /**
     * @param mixed $invoiced
     * @param mixed $refunded
     */
    private function nonNegativeRemainder($invoiced, $refunded, string $label): string
    {
        $remainder = $this->moneyMath->subtract(
            $this->component($invoiced),
            $this->component($refunded)
        );

        return $this->moneyMath->assertNonNegative($remainder, $label);
    }

    /**
     * @param mixed $invoiced
     * @param mixed $refunded
     */
    private function nonNegativeQuantityRemainder(
        $invoiced,
        $refunded,
        string $label
    ): string {
        $remainder = $this->quantityMath->subtract(
            $this->component($invoiced),
            $this->component($refunded)
        );

        return $this->quantityMath->assertNonNegative($remainder, $label);
    }

    private function proportionalComponent(
        string $remainder,
        string $quantity,
        string $availableQuantity
    ): string {
        return bcdiv(
            bcmul($remainder, $quantity, self::HIGH_SCALE),
            $availableQuantity,
            self::HIGH_SCALE
        );
    }

    private function isCanonicalCentRounded(string $actual, string $raw): bool
    {
        $wholeCents = bcdiv($raw, '0.01', 0);
        $floor = bcmul($wholeCents, '0.01', 4);
        $ceiling = bccomp($raw, $floor, self::HIGH_SCALE) === 0
            ? $floor
            : bcadd($floor, '0.01', 4);

        return $this->moneyMath->compare($actual, $floor) === 0
            || $this->moneyMath->compare($actual, $ceiling) === 0;
    }

    private function addSignedHighPrecision(
        string $total,
        string $component,
        int $sign
    ): string {
        return $sign > 0
            ? bcadd($total, $component, self::HIGH_SCALE)
            : bcsub($total, $component, self::HIGH_SCALE);
    }

    private function highPrecisionDifference(
        string $left,
        string $right,
        string $tolerance = self::ROUNDING_TOLERANCE
    ): int {
        $difference = bcsub(
            $this->normalizeHighPrecision($left),
            $this->normalizeHighPrecision($right),
            self::HIGH_SCALE
        );
        if (bccomp($difference, '0', self::HIGH_SCALE) < 0) {
            $difference = bcsub('0', $difference, self::HIGH_SCALE);
        }

        return bccomp($difference, $tolerance, self::HIGH_SCALE);
    }

    private function normalizeHighPrecision(string $value): string
    {
        if (!preg_match('/^-?(?:0|[1-9][0-9]*)(?:\\.[0-9]+)?$/D', $value)) {
            throw new InvariantViolationException(
                __('A native credit memo high-precision value is invalid.')
            );
        }

        return bcadd($value, '0', self::HIGH_SCALE);
    }

    /**
     * @param mixed $item
     */
    private function getItemDataFlag($item, string $field): bool
    {
        return method_exists($item, 'getData') && (bool)$item->getData($field);
    }

    private function getDocumentBaseCost(CreditmemoInterface $creditmemo): ?string
    {
        if (!method_exists($creditmemo, 'getData')) {
            return null;
        }
        $value = $creditmemo->getData('base_cost');

        return $value === null || $value === '' ? '0' : (string)$value;
    }

    /**
     * @return array{tax: string, base_tax: string, compensation: string, base_compensation: string}
     */
    private function getOrderTaxRemainders(OrderInterface $order): array
    {
        $compensationInvoiced = $this->moneyMath->add(
            $this->component($order->getDiscountTaxCompensationInvoiced()),
            $this->component($order->getShippingDiscountTaxCompensationAmount())
        );
        $baseCompensationInvoiced = $this->moneyMath->add(
            $this->component($order->getBaseDiscountTaxCompensationInvoiced()),
            $this->component($order->getBaseShippingDiscountTaxCompensationAmnt())
        );
        $compensationRefunded = $this->moneyMath->add(
            $this->component($order->getDiscountTaxCompensationRefunded()),
            $this->getOrderData(
                $order,
                'shipping_discount_tax_compensation_refunded'
            )
        );
        $baseCompensationRefunded = $this->moneyMath->add(
            $this->component($order->getBaseDiscountTaxCompensationRefunded()),
            $this->getOrderData(
                $order,
                'base_shipping_discount_tax_compensation_refunded'
            )
        );

        return [
            'tax' => $this->nonNegativeRemainder(
                $order->getTaxInvoiced(),
                $order->getTaxRefunded(),
                'Native refundable order tax'
            ),
            'base_tax' => $this->nonNegativeRemainder(
                $order->getBaseTaxInvoiced(),
                $order->getBaseTaxRefunded(),
                'Native refundable base order tax'
            ),
            'compensation' => $this->nonNegativeRemainder(
                $compensationInvoiced,
                $compensationRefunded,
                'Native refundable order discount-tax compensation'
            ),
            'base_compensation' => $this->nonNegativeRemainder(
                $baseCompensationInvoiced,
                $baseCompensationRefunded,
                'Native refundable base order discount-tax compensation'
            ),
        ];
    }

    private function getOrderData(OrderInterface $order, string $field): string
    {
        if (!method_exists($order, 'getData')) {
            return '0.0000';
        }

        return $this->component($order->getData($field));
    }

    private function hasRemainingShippingTaxOrCompensation(OrderInterface $order): bool
    {
        $remainders = [
            $this->nonNegativeRemainder(
                $order->getShippingTaxAmount(),
                $order->getShippingTaxRefunded(),
                'Native refundable shipping tax'
            ),
            $this->nonNegativeRemainder(
                $order->getBaseShippingTaxAmount(),
                $order->getBaseShippingTaxRefunded(),
                'Native refundable base shipping tax'
            ),
            $this->nonNegativeRemainder(
                $order->getShippingDiscountTaxCompensationAmount(),
                $this->getOrderData(
                    $order,
                    'shipping_discount_tax_compensation_refunded'
                ),
                'Native refundable shipping discount-tax compensation'
            ),
            $this->nonNegativeRemainder(
                $order->getBaseShippingDiscountTaxCompensationAmnt(),
                $this->getOrderData(
                    $order,
                    'base_shipping_discount_tax_compensation_refunded'
                ),
                'Native refundable base shipping discount-tax compensation'
            ),
        ];
        foreach ($remainders as $remainder) {
            if ($this->moneyMath->compare($remainder, '0') !== 0) {
                return true;
            }
        }

        return false;
    }
}
