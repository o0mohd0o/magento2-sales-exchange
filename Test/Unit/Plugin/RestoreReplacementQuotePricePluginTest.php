<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Unit\Plugin;

use Bonlineco\SalesExchange\Api\Data\ReplacementItemInterface;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Bonlineco\SalesExchange\Model\ReplacementOrder\ExecutionContext;
use Bonlineco\SalesExchange\Model\ReplacementOrder\Marker;
use Bonlineco\SalesExchange\Plugin\RestoreReplacementQuotePricePlugin;
use Magento\Catalog\Model\Product;
use Magento\Framework\DataObject;
use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\Total;
use Magento\Quote\Model\Quote\Address\Total\Subtotal;
use Magento\Quote\Model\Quote\Item;
use PHPUnit\Framework\TestCase;

class RestoreReplacementQuotePricePluginTest extends TestCase
{
    private const INTENT_HASH =
        'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testOrdinaryQuoteIsUntouched(): void
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAllVisibleItems'])
            ->getMock();
        $quote->expects(self::never())->method('getAllVisibleItems');

        $this->plugin(new ExecutionContext())->beforeCollect(
            $this->subtotal(),
            $quote,
            $this->createMock(ShippingAssignmentInterface::class),
            $this->total()
        );
    }

    public function testTrustedPriceIsRestoredOnEveryCollection(): void
    {
        $context = new ExecutionContext();
        $product = $this->getMockBuilder(Product::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSku'])
            ->getMock();
        $product->method('getSku')->willReturn('replacement-sku');
        $product->setId(21)
            ->setIsSuperMode(true);
        $item = $this->getMockBuilder(Item::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getBuyRequest', 'getProduct'])
            ->getMock();
        $item->setData([
            Marker::REPLACEMENT_ITEM_ID => 71,
            'product_id' => 21,
            'sku' => 'replacement-sku',
            'name' => 'Replacement',
            'qty' => '1.0000',
        ]);
        $item->method('getBuyRequest')->willReturn(
            new DataObject([
                'custom_price' => '12999.0000',
                'original_custom_price' => '12999.0000',
            ])
        );
        $item->method('getProduct')->willReturn($product);
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAllVisibleItems'])
            ->getMock();
        $quote->expects(self::exactly(2))
            ->method('getAllVisibleItems')
            ->willReturn([$item]);
        $quote->setIsSuperMode(true);
        $plugin = $this->plugin($context);

        $context->execute(
            7,
            self::INTENT_HASH,
            function () use (
                $context,
                $quote,
                $plugin,
                $item,
                $product
            ): void {
                $context->markQuote($quote);
                $plugin->beforeCollect(
                    $this->subtotal(),
                    $quote,
                    $this->createMock(ShippingAssignmentInterface::class),
                    $this->total()
                );
                self::assertSame('12999.0000', $item->getCustomPrice());
                self::assertSame(
                    '12999.0000',
                    $item->getOriginalCustomPrice()
                );
                self::assertTrue((bool)$item->getNoDiscount());
                self::assertFalse((bool)$product->getIsSuperMode());

                $item->setCustomPrice(null)
                    ->setOriginalCustomPrice(null)
                    ->setNoDiscount(false);
                $product->setIsSuperMode(true);
                $plugin->beforeCollect(
                    $this->subtotal(),
                    $quote,
                    $this->createMock(ShippingAssignmentInterface::class),
                    $this->total()
                );
                self::assertSame('12999.0000', $item->getCustomPrice());
                self::assertSame(
                    '12999.0000',
                    $item->getOriginalCustomPrice()
                );
                self::assertTrue((bool)$item->getNoDiscount());
                self::assertFalse((bool)$product->getIsSuperMode());
            },
            $this->replacementRows('12999.0000')
        );
        self::assertFalse((bool)$quote->getIsSuperMode());
    }

    public function testTrustedItemRequiresMatchingServerFrozenPrices(): void
    {
        $context = new ExecutionContext();
        $item = $this->item(
            '12999.0000',
            '12000.0000'
        );
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAllVisibleItems'])
            ->getMock();
        $quote->method('getAllVisibleItems')->willReturn([$item]);
        $plugin = $this->plugin($context);
        $this->expectException(InvariantViolationException::class);

        $context->execute(
            7,
            self::INTENT_HASH,
            function () use ($context, $quote, $plugin): void {
                $context->markQuote($quote);
                $plugin->beforeCollect(
                    $this->subtotal(),
                    $quote,
                    $this->createMock(ShippingAssignmentInterface::class),
                    $this->total()
                );
            },
            $this->replacementRows('12999.0000')
        );
    }

    public function testTrustedItemRejectsEqualPriceOutsideServerSnapshot(): void
    {
        $context = new ExecutionContext();
        $item = $this->item(
            '12000.0000',
            '12000.0000'
        );
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAllVisibleItems'])
            ->getMock();
        $quote->method('getAllVisibleItems')->willReturn([$item]);
        $plugin = $this->plugin($context);
        $this->expectException(InvariantViolationException::class);

        $context->execute(
            7,
            self::INTENT_HASH,
            function () use ($context, $quote, $plugin): void {
                $context->markQuote($quote);
                $plugin->beforeCollect(
                    $this->subtotal(),
                    $quote,
                    $this->createMock(ShippingAssignmentInterface::class),
                    $this->total()
                );
            },
            $this->replacementRows('12999.0000')
        );
    }

    public function testTrustedQuoteRejectsMissingFrozenRow(): void
    {
        $rows = $this->replacementRows('12999.0000');
        $rows[] = [
            ReplacementItemInterface::ENTITY_ID => 72,
            ReplacementItemInterface::PRODUCT_ID => 22,
            ReplacementItemInterface::SKU => 'second-sku',
            ReplacementItemInterface::NAME => 'Second Replacement',
            ReplacementItemInterface::QTY => '1.0000',
            ReplacementItemInterface::UNIT_PRICE_AMOUNT => '100.0000',
            ReplacementItemInterface::ROW_TOTAL_AMOUNT => '100.0000',
        ];

        $this->assertSnapshotDriftRejected(
            $this->item('12999.0000', '12999.0000'),
            $rows
        );
    }

    public function testTrustedQuoteRejectsQuantityDrift(): void
    {
        $item = $this->item('12999.0000', '12999.0000');
        $item->setData('qty', '2.0000');

        $this->assertSnapshotDriftRejected(
            $item,
            $this->replacementRows('12999.0000')
        );
    }

    public function testTrustedQuoteRejectsProductDrift(): void
    {
        $item = $this->item('12999.0000', '12999.0000');
        $item->setData('product_id', 22);

        $this->assertSnapshotDriftRejected(
            $item,
            $this->replacementRows('12999.0000')
        );
    }

    private function plugin(
        ExecutionContext $context
    ): RestoreReplacementQuotePricePlugin {
        return new RestoreReplacementQuotePricePlugin(
            $context,
            new DecimalMath(),
            new DecimalMath(4, 12)
        );
    }

    private function subtotal(): Subtotal
    {
        /** @var Subtotal $subtotal */
        $subtotal = (new \ReflectionClass(Subtotal::class))
            ->newInstanceWithoutConstructor();

        return $subtotal;
    }

    private function total(): Total
    {
        /** @var Total $total */
        $total = (new \ReflectionClass(Total::class))
            ->newInstanceWithoutConstructor();

        return $total;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function replacementRows(string $unitPrice): array
    {
        return [[
            ReplacementItemInterface::ENTITY_ID => 71,
            ReplacementItemInterface::PRODUCT_ID => 21,
            ReplacementItemInterface::SKU => 'replacement-sku',
            ReplacementItemInterface::NAME => 'Replacement',
            ReplacementItemInterface::QTY => '1.0000',
            ReplacementItemInterface::UNIT_PRICE_AMOUNT => $unitPrice,
            ReplacementItemInterface::ROW_TOTAL_AMOUNT => $unitPrice,
        ]];
    }

    private function item(
        string $customPrice,
        string $originalCustomPrice
    ): Item {
        $product = $this->getMockBuilder(Product::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSku'])
            ->getMock();
        $product->method('getSku')->willReturn('replacement-sku');
        $product->setId(21);
        $item = $this->getMockBuilder(Item::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getBuyRequest', 'getProduct'])
            ->getMock();
        $item->setData([
            Marker::REPLACEMENT_ITEM_ID => 71,
            'product_id' => 21,
            'sku' => 'replacement-sku',
            'name' => 'Replacement',
            'qty' => '1.0000',
        ]);
        $item->method('getBuyRequest')->willReturn(
            new DataObject([
                'custom_price' => $customPrice,
                'original_custom_price' => $originalCustomPrice,
            ])
        );
        $item->method('getProduct')->willReturn($product);

        return $item;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function assertSnapshotDriftRejected(
        Item $item,
        array $rows
    ): void {
        $context = new ExecutionContext();
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAllVisibleItems'])
            ->getMock();
        $quote->method('getAllVisibleItems')->willReturn([$item]);
        $plugin = $this->plugin($context);
        $this->expectException(InvariantViolationException::class);

        $context->execute(
            7,
            self::INTENT_HASH,
            function () use ($context, $quote, $plugin): void {
                $context->markQuote($quote);
                $plugin->beforeCollect(
                    $this->subtotal(),
                    $quote,
                    $this->createMock(ShippingAssignmentInterface::class),
                    $this->total()
                );
            },
            $rows
        );
    }
}
