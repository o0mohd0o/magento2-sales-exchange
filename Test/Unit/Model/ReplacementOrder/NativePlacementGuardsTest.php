<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Unit\Model\ReplacementOrder;

use Bonlineco\SalesExchange\Api\Data\ExchangeInterface;
use Bonlineco\SalesExchange\Api\Data\ReplacementItemInterface;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Exchange;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Bonlineco\SalesExchange\Model\ReplacementOrder\ExecutionContext;
use Bonlineco\SalesExchange\Model\ReplacementOrder\Marker;
use Bonlineco\SalesExchange\Model\ReplacementOrder\NativeOrderAddressValidator;
use Bonlineco\SalesExchange\Model\ReplacementOrder\NativeOrderValidator;
use Bonlineco\SalesExchange\Observer\CopyReplacementOrderMarkers;
use Bonlineco\SalesExchange\Plugin\MinimumAmountValidationPlugin;
use Magento\Framework\Event\Observer;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\Quote\Model\ValidationRules\MinimumAmountValidationRule;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NativePlacementGuardsTest extends TestCase
{
    private const INTENT_HASH =
        'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testTrustedSubmitObserverCopiesFinalOrderMarkers(): void
    {
        $context = new ExecutionContext();
        $quote = $this->trustedQuote([71, 72]);
        $order = $this->draftOrder([72, 71]);
        $observer = new Observer(['quote' => $quote, 'order' => $order]);

        $context->execute(
            7,
            self::INTENT_HASH,
            static function () use ($context, $quote, $observer): void {
                $context->setPreSubmitValidator(
                    static function (Quote $candidate): void {
                        unset($candidate);
                    }
                );
                $context->setPrePlaceOrderValidator(
                    static function (
                        Quote $submittedQuote,
                        OrderInterface $candidate
                    ): void {
                        unset($submittedQuote, $candidate);
                    }
                );
                $context->markQuote($quote);
                (new CopyReplacementOrderMarkers($context))->execute(
                    $observer
                );
            }
        );

        self::assertSame(7, $order->getData(Marker::EXCHANGE_ID));
        self::assertSame(
            self::INTENT_HASH,
            $order->getData(Marker::INTENT_HASH)
        );
    }

    /**
     * @dataProvider driftedMarkerSetProvider
     * @param array<int, int> $quoteMarkers
     * @param array<int, int> $orderMarkers
     */
    #[DataProvider('driftedMarkerSetProvider')]
    public function testTrustedSubmitRejectsDriftedItemMarkerSet(
        array $quoteMarkers,
        array $orderMarkers
    ): void {
        $context = new ExecutionContext();
        $quote = $this->trustedQuote($quoteMarkers);
        $order = $this->draftOrder($orderMarkers);
        $this->expectException(InvariantViolationException::class);

        $context->execute(
            7,
            self::INTENT_HASH,
            static function () use ($context, $quote, $order): void {
                $context->setPreSubmitValidator(
                    static function (Quote $candidate): void {
                        unset($candidate);
                    }
                );
                $context->setPrePlaceOrderValidator(
                    static function (
                        Quote $submittedQuote,
                        OrderInterface $candidate
                    ): void {
                        unset($submittedQuote, $candidate);
                    }
                );
                $context->markQuote($quote);
                (new CopyReplacementOrderMarkers($context))->execute(
                    new Observer(['quote' => $quote, 'order' => $order])
                );
            }
        );
    }

    /**
     * @return array<string, array{array<int, int>, array<int, int>}>
     */
    public static function driftedMarkerSetProvider(): array
    {
        return [
            'missing order marker' => [[71, 72], [71]],
            'extra order marker' => [[71], [71, 72]],
            'duplicate quote marker' => [[71, 71], [71]],
            'duplicate order marker' => [[71], [71, 71]],
        ];
    }

    public function testSubmitObserverRejectsSpoofedMarkersOutsideContext(): void
    {
        $quote = $this->modelWithoutConstructor(Quote::class);
        $quote->setData(Marker::EXCHANGE_ID, 7)
            ->setData(Marker::INTENT_HASH, self::INTENT_HASH);
        $this->expectException(InvariantViolationException::class);

        (new CopyReplacementOrderMarkers(new ExecutionContext()))->execute(
            new Observer([
                'quote' => $quote,
                'order' => $this->modelWithoutConstructor(Order::class),
            ])
        );
    }

    public function testSubmitObserverLeavesOrdinaryOrderUntouched(): void
    {
        $order = $this->modelWithoutConstructor(Order::class);
        (new CopyReplacementOrderMarkers(new ExecutionContext()))->execute(
            new Observer([
                'quote' => $this->modelWithoutConstructor(Quote::class),
                'order' => $order,
            ])
        );

        self::assertNull($order->getData(Marker::EXCHANGE_ID));
        self::assertNull($order->getData(Marker::INTENT_HASH));
    }

    public function testSubmitObserverRejectsConflictingFinalOrderMarker(): void
    {
        $context = new ExecutionContext();
        $quote = $this->modelWithoutConstructor(Quote::class);
        $quote->setId(41)
            ->setData(Marker::EXCHANGE_ID, 7)
            ->setData(Marker::INTENT_HASH, self::INTENT_HASH);
        $order = $this->modelWithoutConstructor(Order::class);
        $order->setData(Marker::EXCHANGE_ID, 8);
        $this->expectException(InvariantViolationException::class);

        $context->execute(
            7,
            self::INTENT_HASH,
            static function () use ($context, $quote, $order): void {
                $context->setPreSubmitValidator(
                    static function (Quote $candidate): void {
                        unset($candidate);
                    }
                );
                $context->setPrePlaceOrderValidator(
                    static function (
                        Quote $submittedQuote,
                        OrderInterface $candidate
                    ): void {
                        unset($submittedQuote, $candidate);
                    }
                );
                $context->markQuote($quote);
                (new CopyReplacementOrderMarkers($context))->execute(
                    new Observer(['quote' => $quote, 'order' => $order])
                );
            }
        );
    }

    public function testMinimumAmountIsBypassedOnlyForTrustedQuote(): void
    {
        $context = new ExecutionContext();
        $plugin = new MinimumAmountValidationPlugin($context);
        $quote = $this->modelWithoutConstructor(Quote::class);
        $quote->setId(41);
        $subject = $this->getMockBuilder(MinimumAmountValidationRule::class)
            ->disableOriginalConstructor()
            ->getMock();
        $blocked = [$this->createStub(
            \Magento\Framework\Validation\ValidationResult::class
        )];

        self::assertSame(
            $blocked,
            $plugin->afterValidate($subject, $blocked, $quote)
        );
        $context->execute(
            7,
            self::INTENT_HASH,
            static function () use (
                $context,
                $plugin,
                $quote,
                $subject,
                $blocked
            ): void {
                $context->markQuote($quote);
                self::assertSame(
                    [],
                    $plugin->afterValidate($subject, $blocked, $quote)
                );
            }
        );
    }

    public function testNativeFingerprintSurvivesMutableOrderStatus(): void
    {
        $validator = $this->validator();
        $exchange = $this->exchange('100.0000');
        $original = $this->originalOrder();
        $order = $this->replacementOrder('100.0000', '14.0000');
        $rows = [$this->replacementRow('100.0000')];
        self::assertSame(200, (int)$order->getEntityId());
        self::assertSame('000000200', (string)$order->getIncrementId());
        self::assertSame(41, (int)$order->getQuoteId());
        self::assertSame(7, (int)$order->getData(Marker::EXCHANGE_ID));
        self::assertSame(
            self::INTENT_HASH,
            $order->getData(Marker::INTENT_HASH)
        );

        $pending = $validator->snapshot(
            $order,
            $original,
            $exchange,
            $rows,
            self::INTENT_HASH,
            41
        );
        $order->setStatus('complete')->setState(Order::STATE_COMPLETE);
        $complete = $validator->snapshot(
            $order,
            $original,
            $exchange,
            $rows,
            self::INTENT_HASH,
            41
        );

        self::assertSame('114.0000', $pending['amount']);
        self::assertSame('100.0000', $pending['expected_amount']);
        self::assertSame([71 => 501], $pending['item_ids']);
        self::assertSame(
            $pending['snapshot_hash'],
            $complete['snapshot_hash']
        );
    }

    public function testTaxInclusiveCatalogPriceMatchesGrossNativeAmounts(): void
    {
        $snapshot = $this->validator()->snapshot(
            $this->replacementOrder('100.0000', '14.0000'),
            $this->originalOrder(),
            $this->exchange('114.0000'),
            [$this->replacementRow('114.0000')],
            self::INTENT_HASH,
            41
        );

        self::assertSame('114.0000', $snapshot['amount']);
        self::assertSame('114.0000', $snapshot['expected_amount']);
        self::assertSame([71 => 501], $snapshot['item_ids']);
    }

    public function testLegacyNativeFingerprintKeepsPreTaxSnapshotShape(): void
    {
        $order = $this->replacementOrder('100.0000', '14.0000');
        $snapshot = $this->validator()->snapshot(
            $order,
            $this->originalOrder(),
            $this->legacyExchange('100.0000'),
            [$this->replacementRow('100.0000')],
            self::INTENT_HASH,
            41
        );
        $legacyFingerprint = [
            'order' => [
                'entity_id' => 200,
                'increment_id' => '000000200',
                'quote_id' => 41,
                'exchange_id' => 7,
                'intent_hash' => self::INTENT_HASH,
                'store_id' => 1,
                'customer_id' => 9,
                'currency_code' => 'EGP',
                'base_currency_code' => 'EGP',
                'subtotal' => '100.0000',
                'base_subtotal' => '100.0000',
                'tax' => '14.0000',
                'base_tax' => '14.0000',
                'grand_total' => '114.0000',
                'base_grand_total' => '114.0000',
                'shipping_method' =>
                    'bonlineco_sales_exchange_replacement',
                'payment_method' => 'bonlineco_sales_exchange',
            ],
            'items' => [[
                'replacement_item_id' => 71,
                'order_item_id' => 501,
                'product_id' => 21,
                'sku' => 'replacement-sku',
                'name' => 'Replacement',
                'qty' => '1.0000',
                'price' => '100.0000',
                'base_price' => '100.0000',
                'row_total' => '100.0000',
                'base_row_total' => '100.0000',
                'tax' => '14.0000',
                'base_tax' => '14.0000',
            ]],
            'addresses' => [
                'billing' => ['country_id' => 'EG'],
                'shipping' => ['country_id' => 'EG'],
            ],
        ];

        self::assertSame(
            hash('sha256', (new Json())->serialize($legacyFingerprint)),
            $snapshot['snapshot_hash']
        );
    }

    /**
     * @dataProvider cancellationOrRefundDriftProvider
     */
    #[DataProvider('cancellationOrRefundDriftProvider')]
    public function testNativeOrderRejectsCancellationOrRefundDrift(
        ?string $orderField,
        ?string $itemField,
        bool $cancelledState
    ): void {
        $order = $this->replacementOrder('100.0000', '14.0000');
        if ($orderField !== null) {
            $order->setData($orderField, '1.0000');
        }
        if ($itemField !== null) {
            $order->getItems()[0]->setData($itemField, '1.0000');
        }
        if ($cancelledState) {
            $order->setState(Order::STATE_CANCELED);
        }
        $this->expectException(InvariantViolationException::class);

        $this->validator()->snapshot(
            $order,
            $this->originalOrder(),
            $this->exchange('100.0000'),
            [$this->replacementRow('100.0000')],
            self::INTENT_HASH,
            41
        );
    }

    /**
     * @return array<string, array{string|null, string|null, bool}>
     */
    public static function cancellationOrRefundDriftProvider(): array
    {
        return [
            'cancelled state' => [null, null, true],
            'order total cancelled' => ['total_canceled', null, false],
            'base order total cancelled' => [
                'base_total_canceled',
                null,
                false,
            ],
            'order total refunded' => ['total_refunded', null, false],
            'base order total refunded' => [
                'base_total_refunded',
                null,
                false,
            ],
            'item quantity cancelled' => [null, 'qty_canceled', false],
            'item quantity refunded' => [null, 'qty_refunded', false],
        ];
    }

    public function testZeroTotalNativeOrderRemainsAValidDocument(): void
    {
        $snapshot = $this->validator()->snapshot(
            $this->replacementOrder('0.0000', '0.0000'),
            $this->originalOrder(),
            $this->exchange('0.0000'),
            [$this->replacementRow('0.0000')],
            self::INTENT_HASH,
            41
        );

        self::assertSame('0.0000', $snapshot['amount']);
        self::assertSame('0.0000', $snapshot['base_amount']);
        self::assertSame([71 => 501], $snapshot['item_ids']);
    }

    public function testCancelledSnapshotMatchesImmutablePlacementFingerprint(): void
    {
        $validator = $this->validator();
        $order = $this->replacementOrder('100.0000', '14.0000');
        $original = $this->originalOrder();
        $exchange = $this->exchange('100.0000');
        $rows = [$this->replacementRow('100.0000')];
        $placed = $validator->snapshot(
            $order,
            $original,
            $exchange,
            $rows,
            self::INTENT_HASH,
            41
        );
        $order->setState(Order::STATE_CANCELED)
            ->setTotalCanceled('114.0000')
            ->setBaseTotalCanceled('114.0000')
            ->setTotalInvoiced('0.0000')
            ->setBaseTotalInvoiced('0.0000')
            ->setTotalRefunded('0.0000')
            ->setBaseTotalRefunded('0.0000');
        $order->getItems()[0]
            ->setQtyCanceled('1.0000')
            ->setQtyInvoiced('0.0000')
            ->setQtyShipped('0.0000')
            ->setQtyRefunded('0.0000');

        $cancelled = $validator->cancelledSnapshot(
            $order,
            $original,
            $exchange,
            $rows,
            self::INTENT_HASH
        );

        self::assertSame(
            $placed['snapshot_hash'],
            $cancelled['snapshot_hash']
        );
        self::assertSame($placed['item_quantities_json'], $cancelled[
            'item_quantities_json'
        ]);
    }

    public function testCancelledSnapshotRejectsPartialItemCancellation(): void
    {
        $order = $this->replacementOrder('100.0000', '14.0000');
        $order->setState(Order::STATE_CANCELED)
            ->setTotalCanceled('114.0000')
            ->setBaseTotalCanceled('114.0000')
            ->setTotalInvoiced('0.0000')
            ->setBaseTotalInvoiced('0.0000')
            ->setTotalRefunded('0.0000')
            ->setBaseTotalRefunded('0.0000');
        $order->getItems()[0]
            ->setQtyCanceled('0.5000')
            ->setQtyInvoiced('0.0000')
            ->setQtyShipped('0.0000')
            ->setQtyRefunded('0.0000');
        $this->expectException(InvariantViolationException::class);

        $this->validator()->cancelledSnapshot(
            $order,
            $this->originalOrder(),
            $this->exchange('100.0000'),
            [$this->replacementRow('100.0000')],
            self::INTENT_HASH
        );
    }

    public function testNativeOrderRejectsMissingLineMarker(): void
    {
        $order = $this->replacementOrder('100.0000', '14.0000');
        $order->getItems()[0]->unsetData(Marker::REPLACEMENT_ITEM_ID);
        $this->expectException(InvariantViolationException::class);

        $this->validator()->snapshot(
            $order,
            $this->originalOrder(),
            $this->exchange('100.0000'),
            [$this->replacementRow('100.0000')],
            self::INTENT_HASH,
            41
        );
    }

    public function testNativeOrderRejectsUnapprovedDiscountOrFeeDrift(): void
    {
        $order = $this->replacementOrder('100.0000', '14.0000');
        $order->setGrandTotal('113.0000')
            ->setDiscountAmount('-1.0000');
        $this->expectException(InvariantViolationException::class);

        $this->validator()->snapshot(
            $order,
            $this->originalOrder(),
            $this->exchange('100.0000'),
            [$this->replacementRow('100.0000')],
            self::INTENT_HASH,
            41
        );
    }

    public function testNativeOrderRejectsDifferentPreparedQuoteId(): void
    {
        $this->expectException(InvariantViolationException::class);

        $this->validator()->snapshot(
            $this->replacementOrder('100.0000', '14.0000'),
            $this->originalOrder(),
            $this->exchange('100.0000'),
            [$this->replacementRow('100.0000')],
            self::INTENT_HASH,
            42
        );
    }

    private function validator(): NativeOrderValidator
    {
        $addressValidator = $this->createMock(
            NativeOrderAddressValidator::class
        );
        $addressValidator->method('snapshot')->willReturn([
            'billing' => ['country_id' => 'EG'],
            'shipping' => ['country_id' => 'EG'],
        ]);
        return new NativeOrderValidator(
            new DecimalMath(),
            new DecimalMath(4, 12),
            new Json(),
            $addressValidator
        );
    }

    private function exchange(string $amount): ExchangeInterface
    {
        $exchange = $this->modelWithoutConstructor(Exchange::class);
        $exchange->setEntityId(7)
            ->setOriginalOrderId(100)
            ->setStoreId(1)
            ->setCustomerId(9)
            ->setCurrencyCode('EGP')
            ->setBaseCurrencyCode('EGP')
            ->setReplacementAmount($amount)
            ->setShippingAmount('0.0000');
        if ($amount === '114.0000') {
            $exchange->setCatalogPricesIncludeTax(true);
        } else {
            $exchange->setCatalogPricesIncludeTax(false);
        }

        return $exchange;
    }

    private function legacyExchange(string $amount): ExchangeInterface
    {
        $exchange = $this->exchange($amount);
        $exchange->setCatalogPricesIncludeTax(null);

        return $exchange;
    }

    private function originalOrder(): OrderInterface
    {
        $order = $this->modelWithoutConstructor(Order::class);
        $this->setCustomerSnapshot($order);
        $order->setEntityId(100)
            ->setStoreId(1)
            ->setOrderCurrencyCode('EGP')
            ->setBaseCurrencyCode('EGP');

        return $order;
    }

    private function replacementOrder(
        string $subtotal,
        string $tax
    ): Order {
        $order = $this->modelWithoutConstructor(Order::class);
        $this->setCustomerSnapshot($order);
        $item = $this->modelWithoutConstructor(Item::class);
        $item->setData([
            OrderItemInterface::ITEM_ID => 501,
            OrderItemInterface::PRODUCT_ID => 21,
            OrderItemInterface::PRODUCT_TYPE => 'simple',
            OrderItemInterface::SKU => 'replacement-sku',
            OrderItemInterface::NAME => 'Replacement',
            OrderItemInterface::QTY_ORDERED => '1.0000',
            OrderItemInterface::PRICE => $subtotal,
            OrderItemInterface::BASE_PRICE => $subtotal,
            OrderItemInterface::ROW_TOTAL => $subtotal,
            OrderItemInterface::BASE_ROW_TOTAL => $subtotal,
            OrderItemInterface::TAX_AMOUNT => $tax,
            OrderItemInterface::BASE_TAX_AMOUNT => $tax,
            OrderItemInterface::DISCOUNT_AMOUNT => '0.0000',
            OrderItemInterface::BASE_DISCOUNT_AMOUNT => '0.0000',
            Marker::REPLACEMENT_ITEM_ID => 71,
        ]);
        $payment = $this->modelWithoutConstructor(Payment::class);
        $payment->setMethod('bonlineco_sales_exchange');
        $grandTotal = bcadd($subtotal, $tax, 4);
        $item->setPriceInclTax($grandTotal)
            ->setBasePriceInclTax($grandTotal)
            ->setRowTotalInclTax($grandTotal)
            ->setBaseRowTotalInclTax($grandTotal);
        $order->setEntityId(200)
            ->setIncrementId('000000200')
            ->setQuoteId(41)
            ->setStoreId(1)
            ->setOrderCurrencyCode('EGP')
            ->setBaseCurrencyCode('EGP')
            ->setCouponCode(null)
            ->setSubtotal($subtotal)
            ->setBaseSubtotal($subtotal)
            ->setTaxAmount($tax)
            ->setBaseTaxAmount($tax)
            ->setGrandTotal($grandTotal)
            ->setBaseGrandTotal($grandTotal)
            ->setShippingAmount('0.0000')
            ->setBaseShippingAmount('0.0000')
            ->setDiscountAmount('0.0000')
            ->setBaseDiscountAmount('0.0000')
            ->setItems([$item])
            ->setStatus('pending')
            ->setData('shipping_method', 'bonlineco_sales_exchange_replacement')
            ->setData(Marker::EXCHANGE_ID, 7)
            ->setData(Marker::INTENT_HASH, self::INTENT_HASH);
        $order->setPayment($payment);

        return $order;
    }

    private function setCustomerSnapshot(Order $order): void
    {
        $order->setCustomerId(9)
            ->setCustomerEmail('customer@example.com')
            ->setCustomerFirstname('Mona')
            ->setCustomerMiddlename(null)
            ->setCustomerLastname('Ali')
            ->setCustomerPrefix(null)
            ->setCustomerSuffix(null)
            ->setCustomerGroupId(1)
            ->setCustomerIsGuest(false);
    }

    /**
     * @return array<string, mixed>
     */
    private function replacementRow(string $amount): array
    {
        return [
            ReplacementItemInterface::ENTITY_ID => 71,
            ReplacementItemInterface::EXCHANGE_ID => 7,
            ReplacementItemInterface::PRODUCT_ID => 21,
            ReplacementItemInterface::SKU => 'replacement-sku',
            ReplacementItemInterface::NAME => 'Replacement',
            ReplacementItemInterface::QTY => '1.0000',
            ReplacementItemInterface::UNIT_PRICE_AMOUNT => $amount,
            ReplacementItemInterface::ROW_TOTAL_AMOUNT => $amount,
            ReplacementItemInterface::REPLACEMENT_ORDER_ITEM_ID => null,
            ReplacementItemInterface::VERSION => 1,
        ];
    }

    /**
     * @param array<int, int> $markers
     */
    private function trustedQuote(array $markers): Quote
    {
        $items = [];
        foreach ($markers as $marker) {
            $item = $this->modelWithoutConstructor(QuoteItem::class);
            $item->setData(Marker::REPLACEMENT_ITEM_ID, $marker);
            $items[] = $item;
        }
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAllVisibleItems'])
            ->getMock();
        $quote->method('getAllVisibleItems')->willReturn($items);
        $quote->setId(41)
            ->setData(Marker::EXCHANGE_ID, 7)
            ->setData(Marker::INTENT_HASH, self::INTENT_HASH);

        return $quote;
    }

    /**
     * @param array<int, int> $markers
     */
    private function draftOrder(array $markers): Order
    {
        $items = [];
        foreach ($markers as $marker) {
            $item = $this->modelWithoutConstructor(Item::class);
            $item->setData(Marker::REPLACEMENT_ITEM_ID, $marker);
            $items[] = $item;
        }
        $order = $this->modelWithoutConstructor(Order::class);
        $order->setItems($items);

        return $order;
    }

    /**
     * @template T of object
     * @param class-string<T> $className
     * @return T
     */
    private function modelWithoutConstructor(string $className): object
    {
        return (new \ReflectionClass($className))->newInstanceWithoutConstructor();
    }
}
