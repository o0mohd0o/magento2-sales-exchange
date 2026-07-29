<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Unit\Model\ReplacementOrder;

use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Bonlineco\SalesExchange\Model\ReplacementOrder\ConvertedOrderValidator;
use Bonlineco\SalesExchange\Model\ReplacementOrder\Marker;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\Quote\Model\Quote\Payment as QuotePayment;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Sales\Model\Order\Payment as OrderPayment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConvertedOrderValidatorTest extends TestCase
{
    public function testExactConvertedOrderPasses(): void
    {
        [$quote, $order] = $this->documents();

        $this->validator()->execute($quote, $order);

        self::assertCount(1, $order->getItems());
    }

    public function testNativeNullItemDiscountSentinelsPass(): void
    {
        [$quote, $order] = $this->documents();
        /** @var OrderItem $item */
        $item = $order->getItems()[0];
        $item->unsetData(OrderItemInterface::DISCOUNT_AMOUNT);
        $item->unsetData(OrderItemInterface::BASE_DISCOUNT_AMOUNT);

        $this->validator()->execute($quote, $order);

        self::assertNull($item->getDiscountAmount());
        self::assertNull($item->getBaseDiscountAmount());
    }

    /**
     * @dataProvider convertedDriftProvider
     */
    #[DataProvider('convertedDriftProvider')]
    public function testConvertedOrderDriftFailsBeforeSave(
        string $drift
    ): void {
        [$quote, $order] = $this->documents();
        /** @var OrderItem $item */
        $item = $order->getItems()[0];
        switch ($drift) {
            case 'removed':
                $order->setData(OrderInterface::ITEMS, []);
                break;
            case 'quantity':
                $item->setQtyOrdered('2.0000');
                break;
            case 'product':
                $item->setProductId(22);
                break;
            case 'sku':
                $item->setSku('drifted-sku');
                break;
            case 'price':
                $item->setPrice('99.0000');
                break;
            case 'row_total':
                $item->setRowTotal('99.0000');
                break;
            case 'discount':
                $item->setDiscountAmount('1.0000');
                break;
            case 'base_discount':
                $item->setBaseDiscountAmount('1.0000');
                break;
            case 'price_incl_tax':
                $item->setData('price_incl_tax', '113.0000');
                break;
            case 'base_price_incl_tax':
                $item->setData(
                    'base_price_incl_tax',
                    '113.0000'
                );
                break;
            case 'row_total_incl_tax':
                $item->setData(
                    'row_total_incl_tax',
                    '113.0000'
                );
                break;
            case 'base_row_total_incl_tax':
                $item->setData(
                    'base_row_total_incl_tax',
                    '113.0000'
                );
                break;
            case 'marker':
                $order->unsetData(Marker::EXCHANGE_ID);
                break;
            case 'grand_total':
                $order->setGrandTotal('113.0000');
                break;
        }
        $this->expectException(InvariantViolationException::class);

        $this->validator()->execute($quote, $order);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function convertedDriftProvider(): array
    {
        return [
            'removed item' => ['removed'],
            'quantity' => ['quantity'],
            'product' => ['product'],
            'SKU' => ['sku'],
            'unit price' => ['price'],
            'row price' => ['row_total'],
            'discount' => ['discount'],
            'base discount' => ['base_discount'],
            'gross unit price' => ['price_incl_tax'],
            'base gross unit price' => ['base_price_incl_tax'],
            'gross row price' => ['row_total_incl_tax'],
            'base gross row price' => ['base_row_total_incl_tax'],
            'header marker' => ['marker'],
            'order total' => ['grand_total'],
        ];
    }

    private function validator(): ConvertedOrderValidator
    {
        return new ConvertedOrderValidator(
            new DecimalMath(),
            new DecimalMath(4, 12)
        );
    }

    /**
     * @return array{Quote, Order}
     */
    private function documents(): array
    {
        $quoteItem = $this->model(QuoteItem::class);
        $quoteItem->setData([
            Marker::REPLACEMENT_ITEM_ID => 71,
            'product_id' => 21,
            'sku' => 'replacement-sku',
            'name' => 'Replacement',
            'product_type' => 'simple',
            'qty' => '1.0000',
            'converted_price' => '100.0000',
            'calculation_price' => '100.0000',
            'base_calculation_price' => '98.0000',
            'base_price' => '100.0000',
            'row_total' => '100.0000',
            'base_row_total' => '100.0000',
            'price_incl_tax' => '114.0000',
            'base_price_incl_tax' => '114.0000',
            'row_total_incl_tax' => '114.0000',
            'base_row_total_incl_tax' => '114.0000',
            'tax_amount' => '14.0000',
            'base_tax_amount' => '14.0000',
            'discount_amount' => '0.0000',
            'base_discount_amount' => '0.0000',
        ]);
        $address = $this->model(Address::class);
        $address->setData([
            'shipping_method' =>
                'bonlineco_sales_exchange_replacement',
            'tax_amount' => '14.0000',
            'base_tax_amount' => '14.0000',
            'shipping_amount' => '0.0000',
            'base_shipping_amount' => '0.0000',
            'discount_amount' => '0.0000',
            'base_discount_amount' => '0.0000',
        ]);
        $quotePayment = $this->model(QuotePayment::class);
        $quotePayment->setMethod('bonlineco_sales_exchange');
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getAllVisibleItems',
                'getShippingAddress',
                'getPayment',
            ])
            ->getMock();
        $quote->method('getAllVisibleItems')->willReturn([$quoteItem]);
        $quote->method('getShippingAddress')->willReturn($address);
        $quote->method('getPayment')->willReturn($quotePayment);
        $quote->setData([
            'store_id' => 1,
            'customer_id' => 9,
            'quote_currency_code' => 'EGP',
            'base_currency_code' => 'EGP',
            'customer_email' => 'customer@example.com',
            'coupon_code' => null,
            'subtotal' => '100.0000',
            'base_subtotal' => '100.0000',
            'grand_total' => '114.0000',
            'base_grand_total' => '114.0000',
            Marker::EXCHANGE_ID => 7,
            Marker::INTENT_HASH => str_repeat('a', 64),
        ]);
        $quote->setId(41);

        $orderItem = $this->model(OrderItem::class);
        $orderItem->setData([
            Marker::REPLACEMENT_ITEM_ID => 71,
            OrderItemInterface::PRODUCT_ID => 21,
            OrderItemInterface::SKU => 'replacement-sku',
            OrderItemInterface::NAME => 'Replacement',
            OrderItemInterface::PRODUCT_TYPE => 'simple',
            OrderItemInterface::QTY_ORDERED => '1.0000',
            OrderItemInterface::PRICE => '100.0000',
            OrderItemInterface::BASE_PRICE => '100.0000',
            OrderItemInterface::ROW_TOTAL => '100.0000',
            OrderItemInterface::BASE_ROW_TOTAL => '100.0000',
            'price_incl_tax' => '114.0000',
            'base_price_incl_tax' => '114.0000',
            'row_total_incl_tax' => '114.0000',
            'base_row_total_incl_tax' => '114.0000',
            OrderItemInterface::TAX_AMOUNT => '14.0000',
            OrderItemInterface::BASE_TAX_AMOUNT => '14.0000',
            OrderItemInterface::DISCOUNT_AMOUNT => '0.0000',
            OrderItemInterface::BASE_DISCOUNT_AMOUNT => '0.0000',
        ]);
        $orderPayment = $this->model(OrderPayment::class);
        $orderPayment->setMethod('bonlineco_sales_exchange');
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getItems'])
            ->getMock();
        $order->method('getItems')->willReturnCallback(
            static function () use ($order): array {
                return (array)$order->getData(OrderInterface::ITEMS);
            }
        );
        $order->setData([
            'quote_id' => 41,
            'store_id' => 1,
            'customer_id' => 9,
            'order_currency_code' => 'EGP',
            'base_currency_code' => 'EGP',
            'customer_email' => 'customer@example.com',
            'coupon_code' => null,
            'shipping_method' =>
                'bonlineco_sales_exchange_replacement',
            'subtotal' => '100.0000',
            'base_subtotal' => '100.0000',
            'tax_amount' => '14.0000',
            'base_tax_amount' => '14.0000',
            'grand_total' => '114.0000',
            'base_grand_total' => '114.0000',
            'shipping_amount' => '0.0000',
            'base_shipping_amount' => '0.0000',
            'discount_amount' => '0.0000',
            'base_discount_amount' => '0.0000',
            Marker::EXCHANGE_ID => 7,
            Marker::INTENT_HASH => str_repeat('a', 64),
        ]);
        $order->setData(OrderInterface::ITEMS, [$orderItem]);
        $order->setPayment($orderPayment);

        return [$quote, $order];
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function model(string $class): object
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }
}
