<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Unit\Model;

use Bonlineco\SalesExchange\Api\Data\ReplacementItemInterface;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Creation\ReplacementCatalogResolver;
use Bonlineco\SalesExchange\Model\FinancialAggregateCalculator;
use Bonlineco\SalesExchange\Model\FinancialRowCalculator;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Bonlineco\SalesExchange\Model\ReplacementCurrencyCalculator;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Sales\Api\Data\OrderInterface;
use PHPUnit\Framework\TestCase;

/**
 * Verify replacement snapshots match Magento quote currency rounding.
 */
class ReplacementCurrencyCalculatorTest extends TestCase
{
    public function testCatalogUnitAndSingleQuantityRowUseCurrencyPrecision(): void
    {
        $calculator = $this->calculator();

        self::assertSame(
            '33.3300',
            $calculator->convertUnit('33.3333', '1.0000')
        );
        self::assertSame(
            '33.3300',
            $calculator->execute('1.0000', '33.3333')
        );
    }

    public function testFractionalRowRoundsUnitBeforeMultiplication(): void
    {
        $calculator = $this->calculator();

        self::assertSame('10.0100', $calculator->normalizeUnit('10.0050'));
        self::assertSame(
            '15.0200',
            $calculator->execute('1.5000', '10.0050')
        );
    }

    public function testCatalogResolverFreezesCanonicalUnitPrice(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getBaseToOrderRate')->willReturn('1.0000');

        self::assertSame(
            '33.3300',
            $this->catalogResolver()
                ->execute('replacement-sku', $order)
                ->getUnitPrice()
        );
    }

    public function testCatalogResolverAcceptsMagentoStorageScalePadding(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getBaseToOrderRate')->willReturn('1.0000');

        self::assertSame(
            '12999.0000',
            $this->catalogResolver('12999.000000')
                ->execute('replacement-sku', $order)
                ->getUnitPrice()
        );
    }

    public function testExplicitZeroConversionRateFailsClosed(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getBaseToOrderRate')->willReturn('0.0000');
        $this->expectException(InvariantViolationException::class);

        $this->catalogResolver()->execute('replacement-sku', $order);
    }

    public function testMissingLegacyConversionRateDefaultsToOne(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getBaseToOrderRate')->willReturn(null);

        self::assertSame(
            '33.3300',
            $this->catalogResolver()
                ->execute('replacement-sku', $order)
                ->getUnitPrice()
        );
    }

    public function testAggregateRejectsNoncanonicalPersistedUnitPrice(): void
    {
        $moneyMath = new DecimalMath();
        $quantityMath = new DecimalMath(4, 12);
        $replacementCalculator = new ReplacementCurrencyCalculator(
            $moneyMath,
            $quantityMath
        );
        $aggregate = new FinancialAggregateCalculator(
            $moneyMath,
            $quantityMath,
            new FinancialRowCalculator($moneyMath, $quantityMath),
            $replacementCalculator
        );
        $this->expectException(InvariantViolationException::class);

        $aggregate->getReplacementAmount([[
            ReplacementItemInterface::SKU => 'replacement-sku',
            ReplacementItemInterface::NAME => 'Replacement',
            ReplacementItemInterface::QTY => '1.0000',
            ReplacementItemInterface::UNIT_PRICE_AMOUNT => '33.3333',
            ReplacementItemInterface::ROW_TOTAL_AMOUNT => '33.3300',
        ]]);
    }

    private function calculator(): ReplacementCurrencyCalculator
    {
        return new ReplacementCurrencyCalculator(
            new DecimalMath(),
            new DecimalMath(4, 12)
        );
    }

    private function catalogResolver(string $price = '33.3333'): ReplacementCatalogResolver
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(21);
        $product->method('getSku')->willReturn('replacement-sku');
        $product->method('getName')->willReturn('Replacement');
        $product->method('getStatus')->willReturn(Status::STATUS_ENABLED);
        $product->method('getTypeId')->willReturn('simple');
        $product->method('getPrice')->willReturn($price);
        $products = $this->createMock(ProductRepositoryInterface::class);
        $products->method('get')->willReturn($product);

        return new ReplacementCatalogResolver(
            $products,
            $this->calculator(),
            new DecimalMath()
        );
    }
}
