<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\Settlement;

use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Sales\Api\Data\InvoiceInterface;
use Magento\Sales\Api\Data\InvoiceItemInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Model\Order\Invoice as SalesInvoice;

/**
 * Prove the native document is the sole paid full replacement invoice.
 */
class NativeInvoiceValidator
{
    private DecimalMath $moneyMath;

    private DecimalMath $quantityMath;

    private SerializerInterface $serializer;

    public function __construct(
        DecimalMath $moneyMath,
        DecimalMath $quantityMath,
        SerializerInterface $serializer
    ) {
        $this->moneyMath = $moneyMath;
        $this->quantityMath = $quantityMath;
        $this->serializer = $serializer;
    }

    /**
     * @param array<int, string> $expectedQuantities
     * @return array{
     *     amount: string,
     *     base_amount: string,
     *     item_quantities_json: string,
     *     snapshot_hash: string
     * }
     */
    public function snapshot(
        InvoiceInterface $invoice,
        OrderInterface $order,
        Plan $plan,
        array $expectedQuantities
    ): array {
        $this->assertIdentity($invoice, $order, $plan);
        $this->assertDocumentComponents($invoice, $order);
        $invoiceItems = $this->snapshotItems($invoice, $order);
        ksort($expectedQuantities, SORT_NUMERIC);
        if ($invoiceItems['quantities'] !== $expectedQuantities) {
            throw new InvariantViolationException(
                __('The native invoice does not contain the exact full replacement quantities.')
            );
        }
        $this->assertOrderInvoiceState($order, $plan, $expectedQuantities);

        $amount = $this->money($invoice->getGrandTotal());
        $baseAmount = $this->money($invoice->getBaseGrandTotal());
        if ($this->moneyMath->compare($amount, $plan->getReplacementAmount()) !== 0
            || $this->moneyMath->compare(
                $baseAmount,
                $plan->getBaseReplacementAmount()
            ) !== 0
        ) {
            throw new InvariantViolationException(
                __('The native invoice totals differ from the replacement order totals.')
            );
        }

        $fingerprint = [
            'invoice' => [
                'entity_id' => (int)$invoice->getEntityId(),
                'increment_id' => (string)$invoice->getIncrementId(),
                'order_id' => (int)$invoice->getOrderId(),
                'state' => (int)$invoice->getState(),
                'currency_code' => (string)$invoice->getOrderCurrencyCode(),
                'base_currency_code' => (string)$invoice->getBaseCurrencyCode(),
                'grand_total' => $amount,
                'base_grand_total' => $baseAmount,
                'subtotal' => $this->money($invoice->getSubtotal()),
                'base_subtotal' => $this->money($invoice->getBaseSubtotal()),
                'tax_amount' => $this->money($invoice->getTaxAmount()),
                'base_tax_amount' => $this->money($invoice->getBaseTaxAmount()),
                'discount_amount' => $this->money($invoice->getDiscountAmount()),
                'base_discount_amount' => $this->money(
                    $invoice->getBaseDiscountAmount()
                ),
                'shipping_amount' => $this->money($invoice->getShippingAmount()),
                'base_shipping_amount' => $this->money(
                    $invoice->getBaseShippingAmount()
                ),
                'total_qty' => $this->quantity($invoice->getTotalQty()),
                'transaction_id' => null,
            ],
            'items' => $invoiceItems['fingerprint'],
        ];

        return [
            'amount' => $amount,
            'base_amount' => $baseAmount,
            'item_quantities_json' => $this->serializer->serialize(
                $invoiceItems['quantities']
            ),
            'snapshot_hash' => hash(
                'sha256',
                $this->serializer->serialize($fingerprint)
            ),
        ];
    }

    public function hasOperationMarker(
        InvoiceInterface $invoice,
        string $operationKey
    ): bool {
        $comments = $invoice->getComments();
        if (!is_array($comments)) {
            return false;
        }
        foreach ($comments as $comment) {
            if (str_contains((string)$comment->getComment(), $operationKey)) {
                return true;
            }
        }

        return false;
    }

    private function assertIdentity(
        InvoiceInterface $invoice,
        OrderInterface $order,
        Plan $plan
    ): void {
        if ((int)$invoice->getEntityId() <= 0
            || trim((string)$invoice->getIncrementId()) === ''
            || (int)$invoice->getOrderId() !== (int)$order->getEntityId()
            || (int)$invoice->getState() !== SalesInvoice::STATE_PAID
            || (string)$invoice->getOrderCurrencyCode() !== $plan->getCurrencyCode()
            || (string)$invoice->getBaseCurrencyCode()
                !== $plan->getBaseCurrencyCode()
            || trim((string)$invoice->getTransactionId()) !== ''
            || $this->moneyMath->compare(
                $this->money($invoice->getBaseTotalRefunded()),
                '0'
            ) !== 0
        ) {
            throw new InvariantViolationException(
                __('The native replacement invoice identity or paid state is invalid.')
            );
        }
        $payment = $order->getPayment();
        if ($payment === null || trim((string)$payment->getLastTransId()) !== '') {
            throw new InvariantViolationException(
                __('The exchange invoice cannot carry a gateway transaction.')
            );
        }
    }

    /**
     * @return array{
     *     quantities: array<int, string>,
     *     fingerprint: array<int, array<string, string|int>>
     * }
     */
    private function snapshotItems(
        InvoiceInterface $invoice,
        OrderInterface $order
    ): array
    {
        $items = $invoice->getItems();
        if (!is_array($items) || $items === []) {
            throw new InvariantViolationException(
                __('The native replacement invoice has no items.')
            );
        }
        $orderItems = [];
        $sourceItems = $order->getItems();
        if (!is_array($sourceItems)) {
            throw new InvariantViolationException(
                __('The replacement order item collection is unavailable.')
            );
        }
        foreach ($sourceItems as $orderItem) {
            if (!$orderItem instanceof OrderItemInterface
                || (int)$orderItem->getItemId() <= 0
                || isset($orderItems[(int)$orderItem->getItemId()])
            ) {
                throw new InvariantViolationException(
                    __('The replacement order contains invalid or duplicate items.')
                );
            }
            $orderItems[(int)$orderItem->getItemId()] = $orderItem;
        }
        $quantities = [];
        $fingerprint = [];
        foreach ($items as $item) {
            if (!$item instanceof InvoiceItemInterface) {
                throw new InvariantViolationException(
                    __('The native replacement invoice item is invalid.')
                );
            }
            $orderItemId = (int)$item->getOrderItemId();
            $quantity = $this->quantity($item->getQty());
            if ($orderItemId <= 0
                || isset($quantities[$orderItemId])
                || !isset($orderItems[$orderItemId])
                || $this->quantityMath->compare($quantity, '0') <= 0
            ) {
                throw new InvariantViolationException(
                    __('The native invoice contains invalid or duplicate quantities.')
                );
            }
            $this->assertItemComponents(
                $item,
                $orderItems[$orderItemId]
            );
            $quantities[$orderItemId] = $quantity;
            $fingerprint[$orderItemId] = [
                'entity_id' => (int)$item->getEntityId(),
                'order_item_id' => $orderItemId,
                'qty' => $quantity,
                'row_total' => $this->money($item->getRowTotal()),
                'base_row_total' => $this->money($item->getBaseRowTotal()),
                'tax_amount' => $this->money($item->getTaxAmount()),
                'base_tax_amount' => $this->money($item->getBaseTaxAmount()),
                'discount_amount' => $this->money($item->getDiscountAmount()),
                'base_discount_amount' => $this->money(
                    $item->getBaseDiscountAmount()
                ),
            ];
        }
        ksort($quantities, SORT_NUMERIC);
        ksort($fingerprint, SORT_NUMERIC);

        return ['quantities' => $quantities, 'fingerprint' => $fingerprint];
    }

    private function assertDocumentComponents(
        InvoiceInterface $invoice,
        OrderInterface $order
    ): void {
        $pairs = [
            [$invoice->getSubtotal(), $order->getSubtotal()],
            [$invoice->getBaseSubtotal(), $order->getBaseSubtotal()],
            [$invoice->getTaxAmount(), $order->getTaxAmount()],
            [$invoice->getBaseTaxAmount(), $order->getBaseTaxAmount()],
            [$invoice->getDiscountAmount(), $order->getDiscountAmount()],
            [$invoice->getBaseDiscountAmount(), $order->getBaseDiscountAmount()],
            [$invoice->getShippingAmount(), $order->getShippingAmount()],
            [$invoice->getBaseShippingAmount(), $order->getBaseShippingAmount()],
        ];
        foreach ($pairs as [$invoiceAmount, $orderAmount]) {
            if ($this->moneyMath->compare(
                $this->money($invoiceAmount),
                $this->money($orderAmount)
            ) !== 0) {
                throw new InvariantViolationException(
                    __('The native invoice component totals differ from the replacement order.')
                );
            }
        }
    }

    private function assertItemComponents(
        InvoiceItemInterface $invoiceItem,
        OrderItemInterface $orderItem
    ): void {
        $pairs = [
            [$invoiceItem->getRowTotal(), $orderItem->getRowTotal()],
            [$invoiceItem->getBaseRowTotal(), $orderItem->getBaseRowTotal()],
            [$invoiceItem->getTaxAmount(), $orderItem->getTaxAmount()],
            [$invoiceItem->getBaseTaxAmount(), $orderItem->getBaseTaxAmount()],
            [$invoiceItem->getDiscountAmount(), $orderItem->getDiscountAmount()],
            [
                $invoiceItem->getBaseDiscountAmount(),
                $orderItem->getBaseDiscountAmount(),
            ],
        ];
        foreach ($pairs as [$invoiceAmount, $orderAmount]) {
            if ($this->moneyMath->compare(
                $this->money($invoiceAmount),
                $this->money($orderAmount)
            ) !== 0) {
                throw new InvariantViolationException(
                    __('A native invoice item differs from its replacement order item.')
                );
            }
        }
    }

    /**
     * @param array<int, string> $expectedQuantities
     */
    private function assertOrderInvoiceState(
        OrderInterface $order,
        Plan $plan,
        array $expectedQuantities
    ): void {
        if ($this->moneyMath->compare(
            $this->money($order->getTotalInvoiced()),
            $plan->getReplacementAmount()
        ) !== 0
            || $this->moneyMath->compare(
                $this->money($order->getBaseTotalInvoiced()),
                $plan->getBaseReplacementAmount()
            ) !== 0
            || $this->moneyMath->compare(
                $this->money($order->getTotalPaid()),
                $plan->getReplacementAmount()
            ) !== 0
            || $this->moneyMath->compare(
                $this->money($order->getBaseTotalPaid()),
                $plan->getBaseReplacementAmount()
            ) !== 0
            || $this->moneyMath->compare(
                $this->money($order->getTotalRefunded()),
                '0'
            ) !== 0
            || $this->moneyMath->compare(
                $this->money($order->getBaseTotalRefunded()),
                '0'
            ) !== 0
        ) {
            throw new InvariantViolationException(
                __('The replacement order is not exactly paid by its one full invoice.')
            );
        }
        $items = $order->getItems();
        if (!is_array($items)) {
            throw new InvariantViolationException(
                __('The replacement order item collection is unavailable.')
            );
        }
        $seen = [];
        foreach ($items as $item) {
            if (!$item instanceof OrderItemInterface) {
                throw new InvariantViolationException(
                    __('The replacement order item is invalid.')
                );
            }
            $itemId = (int)$item->getItemId();
            if (!isset($expectedQuantities[$itemId])
                || isset($seen[$itemId])
                || $this->quantityMath->compare(
                    $this->quantity($item->getQtyInvoiced()),
                    $expectedQuantities[$itemId]
                ) !== 0
                || $this->quantityMath->compare(
                    $this->quantity($item->getQtyCanceled()),
                    '0'
                ) !== 0
                || $this->quantityMath->compare(
                    $this->quantity($item->getQtyRefunded()),
                    '0'
                ) !== 0
            ) {
                throw new InvariantViolationException(
                    __('The replacement order item invoice state has drifted.')
                );
            }
            $seen[$itemId] = true;
        }
        if (count($seen) !== count($expectedQuantities)) {
            throw new InvariantViolationException(
                __('The replacement order does not match the canonical invoice quantities.')
            );
        }
    }

    /**
     * @param mixed $value
     */
    private function money($value): string
    {
        return $this->moneyMath->normalize(
            $value === null || $value === '' ? '0' : (string)$value
        );
    }

    /**
     * @param mixed $value
     */
    private function quantity($value): string
    {
        return $this->quantityMath->normalize(
            $value === null || $value === '' ? '0' : (string)$value
        );
    }
}
