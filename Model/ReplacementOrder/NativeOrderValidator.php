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
use Magento\Catalog\Model\Product\Type;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Model\Order as SalesOrder;

/**
 * Prove a native order is the full-price commercial replacement document.
 */
class NativeOrderValidator
{
    private DecimalMath $moneyMath;

    private DecimalMath $quantityMath;

    private SerializerInterface $serializer;

    private NativeOrderAddressValidator $addressValidator;

    public function __construct(
        DecimalMath $moneyMath,
        DecimalMath $quantityMath,
        SerializerInterface $serializer,
        NativeOrderAddressValidator $addressValidator
    ) {
        $this->moneyMath = $moneyMath;
        $this->quantityMath = $quantityMath;
        $this->serializer = $serializer;
        $this->addressValidator = $addressValidator;
    }

    /**
     * Status and fulfillment quantities are deliberately excluded from the
     * persistent fingerprint, so a valid retry survives later native updates.
     *
     * @param array<int, array<string, mixed>> $replacementRows
     * @return array{
     *     amount: string,
     *     base_amount: string,
     *     expected_amount: string,
     *     item_quantities_json: string,
     *     snapshot_hash: string,
     *     item_ids: array<int, int>
     * }
     */
    public function snapshot(
        OrderInterface $order,
        OrderInterface $originalOrder,
        ExchangeInterface $exchange,
        array $replacementRows,
        string $intentHash,
        ?int $expectedQuoteId = null
    ): array {
        return $this->buildSnapshot(
            $order,
            $originalOrder,
            $exchange,
            $replacementRows,
            $intentHash,
            $expectedQuoteId,
            false
        );
    }

    /**
     * Rebuild the immutable placement fingerprint after Magento cancellation.
     *
     * Cancellation counters are validated separately and remain excluded from
     * the durable commercial fingerprint, so an atomic cancellation can prove
     * that it is compensating the exact order originally linked to the case.
     *
     * @param array<int, array<string, mixed>> $replacementRows
     * @return array{
     *     amount: string,
     *     base_amount: string,
     *     expected_amount: string,
     *     item_quantities_json: string,
     *     snapshot_hash: string,
     *     item_ids: array<int, int>
     * }
     */
    public function cancelledSnapshot(
        OrderInterface $order,
        OrderInterface $originalOrder,
        ExchangeInterface $exchange,
        array $replacementRows,
        string $intentHash
    ): array {
        return $this->buildSnapshot(
            $order,
            $originalOrder,
            $exchange,
            $replacementRows,
            $intentHash,
            null,
            true
        );
    }

    /**
     * @param array<int, array<string, mixed>> $replacementRows
     * @return array{
     *     amount: string,
     *     base_amount: string,
     *     expected_amount: string,
     *     item_quantities_json: string,
     *     snapshot_hash: string,
     *     item_ids: array<int, int>
     * }
     */
    private function buildSnapshot(
        OrderInterface $order,
        OrderInterface $originalOrder,
        ExchangeInterface $exchange,
        array $replacementRows,
        string $intentHash,
        ?int $expectedQuoteId,
        bool $requireCancelled
    ): array {
        $this->assertIdentity(
            $order,
            $originalOrder,
            $exchange,
            $intentHash,
            $expectedQuoteId
        );
        $this->assertLifecycle($order, $requireCancelled);
        $rows = $this->indexRows($replacementRows, $exchange);
        $includeTaxFieldsInFingerprint = $exchange
            ->getCatalogPricesIncludeTax() === true;
        $pricesIncludeTax = $this->resolvePricesIncludeTax(
            $order,
            $exchange
        );
        $items = $this->snapshotItems(
            $order,
            $rows,
            $requireCancelled,
            $pricesIncludeTax,
            $includeTaxFieldsInFingerprint
        );
        $totals = $this->snapshotTotals(
            $order,
            $exchange,
            $items['totals'],
            $pricesIncludeTax
        );
        $addresses = $this->addressValidator->snapshot(
            $originalOrder,
            $order
        );

        $fingerprint = [
            'order' => [
                'entity_id' => (int)$order->getEntityId(),
                'increment_id' => (string)$order->getIncrementId(),
                'quote_id' => (int)$order->getQuoteId(),
                'exchange_id' => (int)$exchange->getEntityId(),
                'intent_hash' => $intentHash,
                'store_id' => (int)$order->getStoreId(),
                'customer_id' => $order->getCustomerId() === null
                    ? null
                    : (int)$order->getCustomerId(),
                'currency_code' => (string)$order->getOrderCurrencyCode(),
                'base_currency_code' => (string)$order->getBaseCurrencyCode(),
                'subtotal' => $totals['subtotal'],
                'base_subtotal' => $totals['base_subtotal'],
                'tax' => $totals['tax'],
                'base_tax' => $totals['base_tax'],
                'grand_total' => $totals['amount'],
                'base_grand_total' => $totals['base_amount'],
                'shipping_method' => (string)$order->getData('shipping_method'),
                'payment_method' => (string)$order->getPayment()->getMethod(),
            ],
            'items' => $items['fingerprint'],
            'addresses' => $addresses,
        ];

        return [
            'amount' => $totals['amount'],
            'base_amount' => $totals['base_amount'],
            'expected_amount' => $this->moneyMath->add(
                $exchange->getReplacementAmount(),
                $exchange->getShippingAmount()
            ),
            'item_quantities_json' => $this->serializer->serialize(
                $items['quantities']
            ),
            'snapshot_hash' => hash(
                'sha256',
                $this->serializer->serialize($fingerprint)
            ),
            'item_ids' => $items['item_ids'],
        ];
    }

    private function assertLifecycle(
        OrderInterface $order,
        bool $requireCancelled
    ): void {
        $isCancelled = (string)$order->getState()
            === SalesOrder::STATE_CANCELED;
        $hasRefund = !$this->isZero($order->getTotalRefunded())
            || !$this->isZero($order->getBaseTotalRefunded());
        if (!$requireCancelled
            && ($isCancelled
                || !$this->isZero($order->getTotalCanceled())
                || !$this->isZero($order->getBaseTotalCanceled())
                || $hasRefund)
        ) {
            throw new InvariantViolationException(
                __('The native replacement order was cancelled or refunded.')
            );
        }
        if ($requireCancelled
            && (!$isCancelled
                || $hasRefund
                || !$this->isZero($order->getTotalInvoiced())
                || !$this->isZero($order->getBaseTotalInvoiced())
                || $this->moneyMath->compare(
                    (string)$order->getTotalCanceled(),
                    (string)$order->getGrandTotal()
                ) !== 0
                || $this->moneyMath->compare(
                    (string)$order->getBaseTotalCanceled(),
                    (string)$order->getBaseGrandTotal()
                ) !== 0)
        ) {
            throw new InvariantViolationException(
                __('The native replacement order cancellation is incomplete or was refunded.')
            );
        }
    }

    private function assertIdentity(
        OrderInterface $order,
        OrderInterface $originalOrder,
        ExchangeInterface $exchange,
        string $intentHash,
        ?int $expectedQuoteId
    ): void {
        if (!$order instanceof AbstractModel
            || (int)$order->getEntityId() <= 0
            || trim((string)$order->getIncrementId()) === ''
            || (int)$order->getQuoteId() <= 0
            || ($expectedQuoteId !== null
                && (int)$order->getQuoteId() !== $expectedQuoteId)
            || !preg_match('/^[a-f0-9]{64}$/D', $intentHash)
            || (int)$order->getData(Marker::EXCHANGE_ID)
                !== $exchange->getEntityId()
            || !is_string($order->getData(Marker::INTENT_HASH))
            || !hash_equals(
                $intentHash,
                (string)$order->getData(Marker::INTENT_HASH)
            )
        ) {
            throw new InvariantViolationException(
                __('The native replacement order markers or document identity are invalid.')
            );
        }
        if ((int)$originalOrder->getEntityId()
                !== $exchange->getOriginalOrderId()
            || (int)$order->getStoreId() !== $exchange->getStoreId()
            || (string)$order->getOrderCurrencyCode()
                !== $exchange->getCurrencyCode()
            || (string)$order->getBaseCurrencyCode()
                !== $exchange->getBaseCurrencyCode()
        ) {
            throw new InvariantViolationException(
                __('The native replacement order identity does not match its frozen exchange.')
            );
        }
        $orderCustomerId = $order->getCustomerId() === null
            ? null
            : (int)$order->getCustomerId();
        if ($orderCustomerId !== $exchange->getCustomerId()
            || (string)$order->getCustomerEmail()
                !== (string)$originalOrder->getCustomerEmail()
            || (string)$order->getCustomerFirstname()
                !== (string)$originalOrder->getCustomerFirstname()
            || (string)$order->getCustomerMiddlename()
                !== (string)$originalOrder->getCustomerMiddlename()
            || (string)$order->getCustomerLastname()
                !== (string)$originalOrder->getCustomerLastname()
            || (string)$order->getCustomerPrefix()
                !== (string)$originalOrder->getCustomerPrefix()
            || (string)$order->getCustomerSuffix()
                !== (string)$originalOrder->getCustomerSuffix()
            || (int)$order->getCustomerGroupId()
                !== (int)$originalOrder->getCustomerGroupId()
            || (bool)$order->getCustomerIsGuest()
                !== (bool)$originalOrder->getCustomerIsGuest()
            || trim((string)$order->getCouponCode()) !== ''
            || (string)$order->getData('shipping_method')
                !== ReplacementCarrier::CARRIER_CODE
                    . '_' . ReplacementCarrier::METHOD_CODE
            || $order->getPayment() === null
            || (string)$order->getPayment()->getMethod()
                !== ReplacementPayment::CODE
        ) {
            throw new InvariantViolationException(
                __('The native replacement order customer or commercial methods drifted.')
            );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function indexRows(
        array $rows,
        ExchangeInterface $exchange
    ): array {
        $indexed = [];
        foreach ($rows as $row) {
            $id = (int)($row[ReplacementItemInterface::ENTITY_ID] ?? 0);
            if ($id <= 0
                || isset($indexed[$id])
                || (int)($row[ReplacementItemInterface::EXCHANGE_ID] ?? 0)
                    !== $exchange->getEntityId()
            ) {
                throw new InvariantViolationException(
                    __('The replacement item snapshot is invalid or duplicated.')
                );
            }
            $indexed[$id] = $row;
        }
        if ($indexed === []) {
            throw new InvariantViolationException(
                __('A native replacement order requires frozen item rows.')
            );
        }

        return $indexed;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{
     *     item_ids: array<int, int>,
     *     quantities: array<int, string>,
     *     fingerprint: array<int, array<string, mixed>>,
     *     totals: array{subtotal: string, base_subtotal: string, tax: string, base_tax: string}
     * }
     */
    private function snapshotItems(
        OrderInterface $order,
        array $rows,
        bool $requireCancelled,
        bool $pricesIncludeTax,
        bool $includeTaxFieldsInFingerprint
    ): array {
        $itemIds = [];
        $quantities = [];
        $fingerprint = [];
        $subtotal = '0.0000';
        $baseSubtotal = '0.0000';
        $tax = '0.0000';
        $baseTax = '0.0000';
        $items = $order->getItems();
        if (!is_array($items) || count($items) !== count($rows)) {
            throw new InvariantViolationException(
                __('The native order item count does not match the frozen replacement.')
            );
        }
        foreach ($items as $item) {
            if (!$item instanceof OrderItemInterface
                || !$item instanceof AbstractModel
                || (int)$item->getItemId() <= 0
            ) {
                throw new InvariantViolationException(
                    __('The native replacement order item implementation is invalid.')
                );
            }
            $lifecycleMatches = $requireCancelled
                ? $this->isZero($item->getQtyRefunded())
                    && $this->isZero($item->getQtyInvoiced())
                    && $this->isZero($item->getQtyShipped())
                    && $this->quantityMath->compare(
                        (string)$item->getQtyCanceled(),
                        (string)$item->getQtyOrdered()
                    ) === 0
                : $this->isZero($item->getQtyCanceled())
                    && $this->isZero($item->getQtyRefunded());
            if (!$lifecycleMatches) {
                throw new InvariantViolationException(
                    __(
                        'A native replacement order item has an invalid '
                        . 'cancellation or refund state.'
                    )
                );
            }
            $replacementItemId = (int)$item->getData(
                Marker::REPLACEMENT_ITEM_ID
            );
            if (!isset($rows[$replacementItemId])
                || isset($itemIds[$replacementItemId])
            ) {
                throw new InvariantViolationException(
                    __('A native order item has an invalid replacement marker.')
                );
            }
            $row = $rows[$replacementItemId];
            $quantity = $this->quantityMath->normalize(
                (string)$item->getQtyOrdered()
            );
            $price = $this->moneyMath->assertNonNegative(
                (string)$item->getPrice(),
                'Native replacement item price'
            );
            $rowTotal = $this->moneyMath->assertNonNegative(
                (string)$item->getRowTotal(),
                'Native replacement row total'
            );
            $priceInclTax = $pricesIncludeTax
                ? $this->moneyMath->assertNonNegative(
                    (string)$item->getPriceInclTax(),
                    'Native replacement item price including tax'
                )
                : null;
            $rowTotalInclTax = $pricesIncludeTax
                ? $this->moneyMath->assertNonNegative(
                    (string)$item->getRowTotalInclTax(),
                    'Native replacement row total including tax'
                )
                : null;
            $basePriceInclTax = $pricesIncludeTax
                ? $this->moneyMath->assertNonNegative(
                    (string)$item->getBasePriceInclTax(),
                    'Native replacement base item price including tax'
                )
                : null;
            $baseRowTotalInclTax = $pricesIncludeTax
                ? $this->moneyMath->assertNonNegative(
                    (string)$item->getBaseRowTotalInclTax(),
                    'Native replacement base row total including tax'
                )
                : null;
            $basePrice = $this->moneyMath->assertNonNegative(
                (string)$item->getBasePrice(),
                'Native replacement base item price'
            );
            $baseRowTotal = $this->moneyMath->assertNonNegative(
                (string)$item->getBaseRowTotal(),
                'Native replacement base row total'
            );
            $itemTax = $this->moneyMath->assertNonNegative(
                (string)$item->getTaxAmount(),
                'Native replacement item tax'
            );
            $itemBaseTax = $this->moneyMath->assertNonNegative(
                (string)$item->getBaseTaxAmount(),
                'Native replacement base item tax'
            );
            $matches = $item->getParentItemId() === null
                && (string)$item->getProductType() === Type::TYPE_SIMPLE
                && (int)$item->getProductId()
                    === (int)$row[ReplacementItemInterface::PRODUCT_ID]
                && (string)$item->getSku()
                    === (string)$row[ReplacementItemInterface::SKU]
                && (string)$item->getName()
                    === (string)$row[ReplacementItemInterface::NAME]
                && $this->quantityMath->compare(
                    $quantity,
                    (string)$row[ReplacementItemInterface::QTY]
                ) === 0
                && $this->moneyMath->compare(
                    $pricesIncludeTax ? $priceInclTax : $price,
                    (string)$row[ReplacementItemInterface::UNIT_PRICE_AMOUNT]
                ) === 0
                && $this->moneyMath->compare(
                    $pricesIncludeTax ? $rowTotalInclTax : $rowTotal,
                    (string)$row[ReplacementItemInterface::ROW_TOTAL_AMOUNT]
                ) === 0
                // Magento's converted no-discount item deliberately keeps
                // these values NULL in memory even after repository save.
                // The database normalizes them to zero on reload, so accept
                // either native zero sentinel while rejecting every nonzero
                // discount.
                && $this->isZero($item->getDiscountAmount())
                && $this->isZero($item->getBaseDiscountAmount());
            if (!$matches) {
                throw new InvariantViolationException(
                    __('A native order item drifted from its frozen replacement row.')
                );
            }

            $orderItemId = (int)$item->getItemId();
            $itemIds[$replacementItemId] = $orderItemId;
            $quantities[$orderItemId] = $quantity;
            $itemFingerprint = [
                'replacement_item_id' => $replacementItemId,
                'order_item_id' => $orderItemId,
                'product_id' => (int)$item->getProductId(),
                'sku' => (string)$item->getSku(),
                'name' => (string)$item->getName(),
                'qty' => $quantity,
                'price' => $price,
                'base_price' => $basePrice,
                'row_total' => $rowTotal,
                'base_row_total' => $baseRowTotal,
                'tax' => $itemTax,
                'base_tax' => $itemBaseTax,
            ];
            if ($includeTaxFieldsInFingerprint) {
                $itemFingerprint['price_incl_tax'] = $priceInclTax;
                $itemFingerprint['base_price_incl_tax'] =
                    $basePriceInclTax;
                $itemFingerprint['row_total_incl_tax'] = $rowTotalInclTax;
                $itemFingerprint['base_row_total_incl_tax'] =
                    $baseRowTotalInclTax;
            }
            $fingerprint[] = $itemFingerprint;
            $subtotal = $this->moneyMath->add($subtotal, $rowTotal);
            $baseSubtotal = $this->moneyMath->add(
                $baseSubtotal,
                $baseRowTotal
            );
            $tax = $this->moneyMath->add($tax, $itemTax);
            $baseTax = $this->moneyMath->add($baseTax, $itemBaseTax);
        }
        ksort($itemIds, SORT_NUMERIC);
        ksort($quantities, SORT_NUMERIC);
        usort(
            $fingerprint,
            static fn (array $left, array $right): int =>
                $left['replacement_item_id'] <=> $right['replacement_item_id']
        );

        return [
            'item_ids' => $itemIds,
            'quantities' => $quantities,
            'fingerprint' => $fingerprint,
            'totals' => [
                'subtotal' => $subtotal,
                'base_subtotal' => $baseSubtotal,
                'tax' => $tax,
                'base_tax' => $baseTax,
            ],
        ];
    }

    /**
     * @param array{subtotal: string, base_subtotal: string, tax: string, base_tax: string} $items
     * @return array{
     *     amount: string,
     *     base_amount: string,
     *     subtotal: string,
     *     base_subtotal: string,
     *     tax: string,
     *     base_tax: string
     * }
     */
    private function snapshotTotals(
        OrderInterface $order,
        ExchangeInterface $exchange,
        array $items,
        bool $pricesIncludeTax
    ): array {
        $subtotal = $this->moneyMath->assertNonNegative(
            (string)$order->getSubtotal(),
            'Native replacement subtotal'
        );
        $baseSubtotal = $this->moneyMath->assertNonNegative(
            (string)$order->getBaseSubtotal(),
            'Native replacement base subtotal'
        );
        $tax = $this->moneyMath->assertNonNegative(
            (string)$order->getTaxAmount(),
            'Native replacement tax'
        );
        $baseTax = $this->moneyMath->assertNonNegative(
            (string)$order->getBaseTaxAmount(),
            'Native replacement base tax'
        );
        $amount = $this->moneyMath->assertNonNegative(
            (string)$order->getGrandTotal(),
            'Native replacement grand total'
        );
        $baseAmount = $this->moneyMath->assertNonNegative(
            (string)$order->getBaseGrandTotal(),
            'Native replacement base grand total'
        );
        $matches = $this->moneyMath->compare(
            $pricesIncludeTax ? $amount : $subtotal,
            $exchange->getReplacementAmount()
        ) === 0
            && $this->moneyMath->compare($subtotal, $items['subtotal']) === 0
            && $this->moneyMath->compare(
                $baseSubtotal,
                $items['base_subtotal']
            ) === 0
            && $this->moneyMath->compare($tax, $items['tax']) === 0
            && $this->moneyMath->compare($baseTax, $items['base_tax']) === 0
            && $this->moneyMath->compare(
                $amount,
                $this->moneyMath->add($subtotal, $tax)
            ) === 0
            && $this->moneyMath->compare(
                $baseAmount,
                $this->moneyMath->add($baseSubtotal, $baseTax)
            ) === 0
            && $this->moneyMath->compare(
                (string)$order->getShippingAmount(),
                '0'
            ) === 0
            && $this->moneyMath->compare(
                (string)$order->getBaseShippingAmount(),
                '0'
            ) === 0
            && $this->moneyMath->compare(
                (string)$order->getDiscountAmount(),
                '0'
            ) === 0
            && $this->moneyMath->compare(
                (string)$order->getBaseDiscountAmount(),
                '0'
            ) === 0;
        if (!$matches) {
            throw new InvariantViolationException(
                __(
                    'The native replacement order contains a discount, '
                    . 'shipping charge, fee, or unapproved total.'
                )
            );
        }

        return [
            'amount' => $amount,
            'base_amount' => $baseAmount,
            'subtotal' => $subtotal,
            'base_subtotal' => $baseSubtotal,
            'tax' => $tax,
            'base_tax' => $baseTax,
        ];
    }

    /**
     * Resolve a durable tax basis without consulting mutable store config.
     *
     * Legacy native orders created before the tax-mode snapshot column can be
     * proven from their own immutable subtotal/grand-total relationship.
     */
    private function resolvePricesIncludeTax(
        OrderInterface $order,
        ExchangeInterface $exchange
    ): bool {
        $frozen = $exchange->getCatalogPricesIncludeTax();
        if ($frozen !== null) {
            return $frozen;
        }

        $approved = $exchange->getReplacementAmount();
        $matchesExclusive = $this->moneyMath->compare(
            (string)$order->getSubtotal(),
            $approved
        ) === 0;
        $matchesInclusive = $this->moneyMath->compare(
            (string)$order->getGrandTotal(),
            $approved
        ) === 0;
        if ($matchesExclusive xor $matchesInclusive) {
            return $matchesInclusive;
        }
        if ($matchesExclusive && $matchesInclusive) {
            return false;
        }

        throw new InvariantViolationException(
            __(
                'The legacy native replacement order tax basis cannot be '
                . 'proven from its immutable totals.'
            )
        );
    }

    /**
     * Magento stores untouched fulfillment totals as either NULL or zero.
     *
     * @param mixed $value
     */
    private function isZero($value): bool
    {
        return $value === null
            || $value === ''
            || $this->moneyMath->compare((string)$value, '0') === 0;
    }
}
