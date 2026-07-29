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
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Bonlineco\SalesExchange\Model\ReplacementOrder\AddressSnapshotCopier;
use Bonlineco\SalesExchange\Model\ReplacementOrder\QuoteValidator;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Item;
use Magento\Tax\Helper\Data as TaxHelper;
use Magento\Tax\Model\Config as TaxConfig;
use PHPUnit\Framework\TestCase;

class QuoteValidatorTaxTest extends TestCase
{
    public function testTaxInclusiveItemUsesFrozenGrossAmounts(): void
    {
        $validator = $this->validator(true);
        $item = $this->item('114.0000', '100.0000', '14.0000');

        $this->invoke(
            $validator,
            'assertItemSnapshot',
            [$item, $this->row('114.0000'), 1, true]
        );
    }

    public function testTaxInclusiveItemRejectsGrossPriceDrift(): void
    {
        $validator = $this->validator(true);
        $this->expectException(
            \Bonlineco\SalesExchange\Exception\InvariantViolationException::class
        );

        $this->invoke(
            $validator,
            'assertItemSnapshot',
            [
                $this->item('115.0000', '100.0000', '15.0000'),
                $this->row('114.0000'),
                1,
                true,
            ]
        );
    }

    public function testItemWithoutOriginalCustomPriceFailsClosed(): void
    {
        $validator = $this->validator(true);
        $item = $this->item('114.0000', '100.0000', '14.0000');
        $item->setOriginalCustomPrice(null);
        $this->expectException(
            \Bonlineco\SalesExchange\Exception\InvariantViolationException::class
        );

        $this->invoke(
            $validator,
            'assertItemSnapshot',
            [$item, $this->row('114.0000'), 1, true]
        );
    }

    public function testTaxInclusiveTotalsUseGrandTotalAsApprovedAmount(): void
    {
        $validator = $this->validator(true);
        $exchange = $this->createMock(ExchangeInterface::class);
        $exchange->method('getReplacementAmount')->willReturn('114.0000');

        $this->invoke(
            $validator,
            'assertTotals',
            [
                $this->quote('100.0000', '14.0000'),
                $exchange,
                true,
                $this->itemTotals('100.0000', '14.0000'),
            ]
        );
    }

    public function testTaxExclusiveTotalsUseSubtotalAsApprovedAmount(): void
    {
        $validator = $this->validator(false);
        $exchange = $this->createMock(ExchangeInterface::class);
        $exchange->method('getReplacementAmount')->willReturn('100.0000');

        $this->invoke(
            $validator,
            'assertTotals',
            [
                $this->quote('100.0000', '14.0000'),
                $exchange,
                false,
                $this->itemTotals('100.0000', '14.0000'),
            ]
        );
    }

    public function testTotalsRejectItemNetTaxDecompositionDrift(): void
    {
        $validator = $this->validator(true);
        $exchange = $this->createMock(ExchangeInterface::class);
        $exchange->method('getReplacementAmount')->willReturn('114.0000');
        $this->expectException(
            \Bonlineco\SalesExchange\Exception\InvariantViolationException::class
        );

        $this->invoke(
            $validator,
            'assertTotals',
            [
                $this->quote('100.0000', '14.0000'),
                $exchange,
                true,
                $this->itemTotals('90.0000', '24.0000'),
            ]
        );
    }

    public function testTaxExclusiveItemUsesConvertedNetAmounts(): void
    {
        $this->invoke(
            $this->validator(false),
            'assertItemSnapshot',
            [
                $this->item('100.0000', '100.0000', '14.0000'),
                $this->row('100.0000'),
                1,
                false,
            ]
        );
    }

    public function testFrozenTaxModeRejectsCurrentConfigurationDrift(): void
    {
        $exchange = $this->createMock(ExchangeInterface::class);
        $exchange->method('getStoreId')->willReturn(3);
        $exchange->method('getCatalogPricesIncludeTax')->willReturn(true);
        $this->expectException(
            \Bonlineco\SalesExchange\Exception\InvariantViolationException::class
        );

        $this->invoke(
            $this->validator(false),
            'resolvePricesIncludeTax',
            [$exchange]
        );
    }

    public function testMissingFrozenTaxModeFailsClosed(): void
    {
        $exchange = $this->createMock(ExchangeInterface::class);
        $exchange->method('getStoreId')->willReturn(3);
        $exchange->method('getCatalogPricesIncludeTax')->willReturn(null);
        $this->expectException(
            \Bonlineco\SalesExchange\Exception\InvariantViolationException::class
        );

        $this->invoke(
            $this->validator(true),
            'resolvePricesIncludeTax',
            [$exchange]
        );
    }

    public function testOriginalPriceTaxModeFailsClosed(): void
    {
        $exchange = $this->createMock(ExchangeInterface::class);
        $exchange->method('getStoreId')->willReturn(3);
        $exchange->method('getCatalogPricesIncludeTax')->willReturn(true);
        $this->expectException(
            \Bonlineco\SalesExchange\Exception\InvariantViolationException::class
        );

        $this->invoke(
            $this->validator(true, false),
            'resolvePricesIncludeTax',
            [$exchange]
        );
    }

    private function validator(
        bool $currentPricesIncludeTax,
        bool $applyTaxOnCustomPrice = true
    ): QuoteValidator {
        $product = $this->getMockBuilder(Product::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSku', 'getIsVirtual', 'isSalable'])
            ->getMock();
        $product->setId(21)
            ->setStatus(Status::STATUS_ENABLED)
            ->setTypeId(Type::TYPE_SIMPLE)
            ->setData('has_options', false);
        $product->method('getSku')->willReturn('replacement-sku');
        $product->method('getIsVirtual')->willReturn(false);
        $product->method('isSalable')->willReturn(true);

        $productRepository = $this->createMock(
            ProductRepositoryInterface::class
        );
        $productRepository->method('getById')->willReturn($product);

        $taxConfig = $this->createMock(TaxConfig::class);
        $taxConfig->method('priceIncludesTax')
            ->willReturn($currentPricesIncludeTax);
        $taxHelper = $this->createMock(TaxHelper::class);
        $taxHelper->method('applyTaxOnCustomPrice')
            ->willReturn($applyTaxOnCustomPrice);

        return new QuoteValidator(
            $productRepository,
            $this->createMock(AddressSnapshotCopier::class),
            new DecimalMath(),
            new DecimalMath(4, 12),
            $taxConfig,
            $taxHelper
        );
    }

    private function item(
        string $gross,
        string $net,
        string $tax
    ): Item {
        $item = $this->getMockBuilder(Item::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getProductType'])
            ->getMock();
        $item->method('getProductType')->willReturn(Type::TYPE_SIMPLE);
        $item->setData([
            'product_id' => 21,
            'sku' => 'replacement-sku',
            'name' => 'Replacement',
            'is_virtual' => false,
            'no_discount' => true,
            'applied_rule_ids' => null,
            'qty' => '1.0000',
            'custom_price' => $net,
            'original_custom_price' => $gross,
            'converted_price' => $net,
            'price_incl_tax' => $gross,
            'row_total' => $net,
            'row_total_incl_tax' => $gross,
            'tax_amount' => $tax,
            'base_row_total' => $net,
            'base_tax_amount' => $tax,
            'discount_amount' => '0.0000',
            'base_discount_amount' => '0.0000',
        ]);

        return $item;
    }

    private function quote(string $subtotal, string $tax): Quote
    {
        /** @var Address $address */
        $address = (new \ReflectionClass(Address::class))
            ->newInstanceWithoutConstructor();
        $grandTotal = bcadd($subtotal, $tax, 4);
        $address->setData([
            'tax_amount' => $tax,
            'base_tax_amount' => $tax,
            'grand_total' => $grandTotal,
            'shipping_amount' => '0.0000',
            'base_shipping_amount' => '0.0000',
            'shipping_tax_amount' => '0.0000',
            'base_shipping_tax_amount' => '0.0000',
            'discount_amount' => '0.0000',
            'base_discount_amount' => '0.0000',
            'shipping_discount_amount' => '0.0000',
            'base_shipping_discount_amount' => '0.0000',
        ]);
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getShippingAddress'])
            ->getMock();
        $quote->method('getShippingAddress')->willReturn($address);
        $quote->setData([
            'subtotal' => $subtotal,
            'base_subtotal' => $subtotal,
            'subtotal_with_discount' => $subtotal,
            'grand_total' => $grandTotal,
            'base_grand_total' => $grandTotal,
        ]);

        return $quote;
    }

    /**
     * @return array{
     *     subtotal: string,
     *     base_subtotal: string,
     *     tax: string,
     *     base_tax: string
     * }
     */
    private function itemTotals(string $subtotal, string $tax): array
    {
        return [
            'subtotal' => $subtotal,
            'base_subtotal' => $subtotal,
            'tax' => $tax,
            'base_tax' => $tax,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $gross): array
    {
        return [
            ReplacementItemInterface::PRODUCT_ID => 21,
            ReplacementItemInterface::SKU => 'replacement-sku',
            ReplacementItemInterface::NAME => 'Replacement',
            ReplacementItemInterface::QTY => '1.0000',
            ReplacementItemInterface::UNIT_PRICE_AMOUNT => $gross,
            ReplacementItemInterface::ROW_TOTAL_AMOUNT => $gross,
        ];
    }

    /**
     * @param array<int, mixed> $arguments
     * @return mixed
     */
    private function invoke(
        object $target,
        string $method,
        array $arguments
    ) {
        return (new \ReflectionMethod($target, $method))
            ->invokeArgs($target, $arguments);
    }
}
