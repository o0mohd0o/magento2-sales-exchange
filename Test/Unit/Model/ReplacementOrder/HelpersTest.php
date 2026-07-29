<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Unit\Model\ReplacementOrder;

use Bonlineco\SalesExchange\Api\CreateReplacementOrderInterface;
use Bonlineco\SalesExchange\Api\Data\DocumentLinkInterface;
use Bonlineco\SalesExchange\Api\Data\ExchangeInterface;
use Bonlineco\SalesExchange\Api\Data\ReplacementItemInterface;
use Bonlineco\SalesExchange\Api\Status\ExchangeStatus;
use Bonlineco\SalesExchange\Api\Status\ReplacementStatus;
use Bonlineco\SalesExchange\Api\Status\ReturnStatus;
use Bonlineco\SalesExchange\Api\Status\SettlementStatus;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\FinancialAggregateCalculator;
use Bonlineco\SalesExchange\Model\FinancialRowCalculator;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Bonlineco\SalesExchange\Model\ReplacementCurrencyCalculator;
use Bonlineco\SalesExchange\Model\ReplacementOrder\EligibilityValidator;
use Bonlineco\SalesExchange\Model\ReplacementOrder\ExecutionContext;
use Bonlineco\SalesExchange\Model\ReplacementOrder\IntentHasher;
use Bonlineco\SalesExchange\Model\ReplacementOrder\Marker;
use Bonlineco\SalesExchange\Model\ReplacementOrder\AddressSnapshotCopier;
use Bonlineco\SalesExchange\Model\ReplacementOrder\QuotePreparer;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Framework\Api\SearchCriteriaInterface;
use Bonlineco\SalesExchange\Plugin\OrderItemMarkerConverterPlugin;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address as QuoteAddress;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\Quote\Model\Quote\Item\ToOrderItem;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Api\Data\OrderAddressSearchResultInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderAddressRepositoryInterface;
use Magento\Sales\Model\Order\Item as OrderItem;
use PHPUnit\Framework\TestCase;

/**
 * Focused policy coverage for replacement quote preparation primitives.
 */
class HelpersTest extends TestCase
{
    public function testIntentIsStableAfterOrderedStatusAndNativeItemLinks(): void
    {
        $hasher = $this->hasher();
        $ready = $this->exchange(ReplacementStatus::READY);
        $ordered = $this->exchange(ReplacementStatus::ORDERED);
        $rows = [$this->row(22, 202), $this->row(11, 101)];
        $linkedRows = [
            $this->row(11, 101, 701),
            $this->row(22, 202, 702),
        ];

        self::assertSame(
            $hasher->execute($ready, $rows),
            $hasher->execute($ordered, $linkedRows)
        );
    }

    public function testIntentIncludesFrozenCatalogTaxMode(): void
    {
        $hasher = $this->hasher();
        $rows = [$this->row(11, 101)];

        self::assertNotSame(
            $hasher->execute(
                $this->exchange(
                    ReplacementStatus::READY,
                    '0.0000',
                    '15.0000',
                    false
                ),
                $rows
            ),
            $hasher->execute(
                $this->exchange(
                    ReplacementStatus::READY,
                    '0.0000',
                    '15.0000',
                    true
                ),
                $rows
            )
        );
    }

    public function testLegacyIntentHashKeepsPreTaxSnapshotShape(): void
    {
        $exchange = $this->exchange(
            ReplacementStatus::READY,
            '0.0000',
            '15.0000',
            null
        );
        $rows = [$this->row(11, 101)];
        $legacySnapshot = [
            'exchange' => [
                'entity_id' => 5,
                'increment_id' => 'EX-TEST',
                'original_order_id' => 50,
                'store_id' => 1,
                'customer_id' => 7,
                'currency_code' => 'EGP',
                'base_currency_code' => 'EGP',
                'replacement_amount' => '15.0000',
                'shipping_amount' => '0.0000',
                'fee_amount' => '0.0000',
            ],
            'replacement_items' => [[
                'entity_id' => 11,
                'product_id' => 101,
                'sku' => 'sku-101',
                'name' => 'Product 101',
                'qty' => '1.0000',
                'unit_price_amount' => '15.0000',
                'row_total_amount' => '15.0000',
                'product_options_json' => null,
            ]],
        ];

        self::assertSame(
            hash('sha256', (new Json())->serialize($legacySnapshot)),
            $this->hasher()->execute($exchange, $rows)
        );
    }

    public function testLifecycleEligibilityRequiresReadyButSnapshotSupportsReplay(): void
    {
        $validator = $this->eligibilityValidator();
        $ordered = $this->exchange(
            ReplacementStatus::ORDERED,
            '0.0000',
            '15.0000'
        );
        $validator->assertSnapshot(
            $ordered,
            [$this->row(11, 101, 701)]
        );

        $this->expectException(InvariantViolationException::class);
        $validator->execute($ordered, [$this->row(11, 101, 701)]);
    }

    public function testEligibilityFailsClosedOnApprovedShipping(): void
    {
        $this->expectException(InvariantViolationException::class);

        $this->eligibilityValidator()->assertSnapshot(
            $this->exchange(ReplacementStatus::READY, '1.0000', '15.0000'),
            [$this->row(11, 101)]
        );
    }

    public function testEligibilityRejectsConfiguredReplacementOptions(): void
    {
        $row = $this->row(11, 101);
        $row[ReplacementItemInterface::PRODUCT_OPTIONS_JSON] = '{"size":"L"}';
        $this->expectException(InvariantViolationException::class);

        $this->eligibilityValidator()->assertSnapshot(
            $this->exchange(ReplacementStatus::READY, '0.0000', '15.0000'),
            [$row]
        );
    }

    public function testTrustedConverterCopiesStableItemMarker(): void
    {
        $context = new ExecutionContext();
        $plugin = new OrderItemMarkerConverterPlugin($context);
        $quote = $this->modelWithoutConstructor(Quote::class);
        $quoteItem = $this->getMockBuilder(QuoteItem::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuote'])
            ->getMock();
        $quoteItem->method('getQuote')->willReturn($quote);
        $quoteItem->setData(Marker::REPLACEMENT_ITEM_ID, 71);
        $orderItem = $this->modelWithoutConstructor(OrderItem::class);
        $subject = $this->getMockBuilder(ToOrderItem::class)
            ->disableOriginalConstructor()
            ->getMock();

        $result = $context->execute(
            5,
            str_repeat('a', 64),
            function () use (
                $context,
                $plugin,
                $quote,
                $quoteItem,
                $orderItem,
                $subject
            ): OrderItem {
                $context->markQuote($quote);
                /** @var OrderItem $converted */
                $converted = $plugin->afterConvert(
                    $subject,
                    $orderItem,
                    $quoteItem
                );

                return $converted;
            }
        );

        self::assertSame(
            71,
            $result->getData(Marker::REPLACEMENT_ITEM_ID)
        );
    }

    public function testUntrustedConverterRejectsSpoofedMarker(): void
    {
        $plugin = new OrderItemMarkerConverterPlugin(new ExecutionContext());
        $quote = $this->modelWithoutConstructor(Quote::class);
        $quoteItem = $this->getMockBuilder(QuoteItem::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuote'])
            ->getMock();
        $quoteItem->method('getQuote')->willReturn($quote);
        $quoteItem->setData(Marker::REPLACEMENT_ITEM_ID, 71);
        $orderItem = $this->modelWithoutConstructor(OrderItem::class);
        $this->expectException(InvariantViolationException::class);

        $plugin->afterConvert(
            $this->getMockBuilder(ToOrderItem::class)
                ->disableOriginalConstructor()
                ->getMock(),
            $orderItem,
            $quoteItem
        );
    }

    public function testPublicCommandContractReturnsDocumentLink(): void
    {
        $method = new \ReflectionMethod(
            CreateReplacementOrderInterface::class,
            'execute'
        );

        self::assertSame(
            DocumentLinkInterface::class,
            (string)$method->getReturnType()
        );
        self::assertSame(
            [
                'exchangeId',
                'expectedVersion',
                'actorId',
                'comment',
            ],
            array_map(
                static fn (\ReflectionParameter $parameter): string =>
                    $parameter->getName(),
                $method->getParameters()
            )
        );
    }

    public function testAddressCopyUsesOrderSnapshotsWithoutAddressBookLinks(): void
    {
        $billingSource = $this->orderAddress('billing', 'Cairo');
        $shippingSource = $this->orderAddress('shipping', 'Giza');
        $searchResult = $this->createMock(
            OrderAddressSearchResultInterface::class
        );
        $searchResult->method('getItems')
            ->willReturn([$billingSource, $shippingSource]);
        $repository = $this->createMock(
            OrderAddressRepositoryInterface::class
        );
        $repository->expects(self::exactly(2))
            ->method('getList')
            ->willReturn($searchResult);
        $criteria = $this->createMock(SearchCriteriaInterface::class);
        $builder = $this->createMock(SearchCriteriaBuilder::class);
        $builder->method('addFilter')->willReturnSelf();
        $builder->method('setPageSize')->willReturnSelf();
        $builder->method('create')->willReturn($criteria);
        $builderFactory = $this->createMock(
            SearchCriteriaBuilderFactory::class
        );
        $builderFactory->method('create')->willReturn($builder);
        $billingTarget = $this->quoteAddress();
        $shippingTarget = $this->quoteAddress();
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getBillingAddress', 'getShippingAddress'])
            ->getMock();
        $quote->method('getBillingAddress')->willReturn($billingTarget);
        $quote->method('getShippingAddress')->willReturn($shippingTarget);
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(50);
        $order->method('getCustomerId')->willReturn(7);
        $order->method('getCustomerEmail')->willReturn('order@example.com');
        $copier = new AddressSnapshotCopier($repository, $builderFactory);

        $copier->execute($order, $quote);
        $copier->assertMatches($order, $quote);

        self::assertSame('Cairo', $billingTarget->getCity());
        self::assertSame('Giza', $shippingTarget->getCity());
        self::assertSame(7, (int)$shippingTarget->getCustomerId());
        self::assertNull($shippingTarget->getCustomerAddressId());
        self::assertFalse((bool)$shippingTarget->getSaveInAddressBook());
    }

    public function testAddressMatchUsesRegionIdInsteadOfLocalizedLabel(): void
    {
        $billingSource = $this->orderAddress(
            'billing',
            'Cairo',
            'القاهرة',
            2437,
            'Cairo'
        );
        $shippingSource = $this->orderAddress(
            'shipping',
            'Giza',
            'القاهرة',
            2437,
            'Cairo'
        );
        $searchResult = $this->createMock(
            OrderAddressSearchResultInterface::class
        );
        $searchResult->method('getItems')
            ->willReturn([$billingSource, $shippingSource]);
        $repository = $this->createMock(
            OrderAddressRepositoryInterface::class
        );
        $repository->method('getList')->willReturn($searchResult);
        $criteria = $this->createMock(SearchCriteriaInterface::class);
        $builder = $this->createMock(SearchCriteriaBuilder::class);
        $builder->method('addFilter')->willReturnSelf();
        $builder->method('setPageSize')->willReturnSelf();
        $builder->method('create')->willReturn($criteria);
        $builderFactory = $this->createMock(
            SearchCriteriaBuilderFactory::class
        );
        $builderFactory->method('create')->willReturn($builder);
        $billingTarget = $this->quoteAddress();
        $shippingTarget = $this->quoteAddress();
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getBillingAddress', 'getShippingAddress'])
            ->getMock();
        $quote->method('getBillingAddress')->willReturn($billingTarget);
        $quote->method('getShippingAddress')->willReturn($shippingTarget);
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(50);
        $order->method('getCustomerId')->willReturn(7);
        $order->method('getCustomerEmail')->willReturn('order@example.com');
        $copier = new AddressSnapshotCopier($repository, $builderFactory);

        $copier->execute($order, $quote);
        $billingTarget->setData('region', 'Cairo');
        $shippingTarget->setData('region', 'Cairo');
        $shippingTarget->setSameAsBilling(1);

        $copier->assertMatches($order, $quote);

        self::assertSame('Cairo', $billingTarget->getRegion());
        self::assertSame('Cairo', $shippingTarget->getRegion());
    }

    public function testAddressCopyRejectsSameQuoteAddressInstance(): void
    {
        $searchResult = $this->createMock(
            OrderAddressSearchResultInterface::class
        );
        $searchResult->method('getItems')->willReturn([
            $this->orderAddress('billing', 'Cairo'),
            $this->orderAddress('shipping', 'Giza'),
        ]);
        $repository = $this->createMock(
            OrderAddressRepositoryInterface::class
        );
        $repository->method('getList')->willReturn($searchResult);
        $criteria = $this->createMock(SearchCriteriaInterface::class);
        $builder = $this->createMock(SearchCriteriaBuilder::class);
        $builder->method('addFilter')->willReturnSelf();
        $builder->method('setPageSize')->willReturnSelf();
        $builder->method('create')->willReturn($criteria);
        $builderFactory = $this->createMock(
            SearchCriteriaBuilderFactory::class
        );
        $builderFactory->method('create')->willReturn($builder);
        $sharedAddress = $this->quoteAddress();
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getBillingAddress', 'getShippingAddress'])
            ->getMock();
        $quote->method('getBillingAddress')->willReturn($sharedAddress);
        $quote->method('getShippingAddress')->willReturn($sharedAddress);
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(50);
        $order->method('getCustomerId')->willReturn(7);
        $order->method('getCustomerEmail')->willReturn('order@example.com');
        $this->expectException(InvariantViolationException::class);

        (new AddressSnapshotCopier($repository, $builderFactory))->execute(
            $order,
            $quote
        );
    }

    public function testQuotePreparerDoesNotDependOnCustomerActiveCartApi(): void
    {
        $constructor = new \ReflectionMethod(QuotePreparer::class, '__construct');
        $types = array_map(
            static fn (\ReflectionParameter $parameter): string =>
                (string)$parameter->getType(),
            $constructor->getParameters()
        );

        self::assertNotContains(
            \Magento\Quote\Api\CartManagementInterface::class,
            $types
        );
    }

    private function eligibilityValidator(): EligibilityValidator
    {
        $moneyMath = new DecimalMath();
        $quantityMath = new DecimalMath(4, 12);
        $rowCalculator = new FinancialRowCalculator(
            $moneyMath,
            $quantityMath
        );

        return new EligibilityValidator(
            new FinancialAggregateCalculator(
                $moneyMath,
                $quantityMath,
                $rowCalculator,
                new ReplacementCurrencyCalculator($moneyMath, $quantityMath)
            ),
            $moneyMath,
            $quantityMath
        );
    }

    private function hasher(): IntentHasher
    {
        return new IntentHasher(
            $this->eligibilityValidator(),
            new DecimalMath(),
            new DecimalMath(4, 12),
            new Json()
        );
    }

    private function exchange(
        string $replacementStatus,
        string $shippingAmount = '0.0000',
        string $replacementAmount = '30.0000',
        ?bool $catalogPricesIncludeTax = null
    ): ExchangeInterface {
        $exchange = $this->createMock(ExchangeInterface::class);
        $exchange->method('getEntityId')->willReturn(5);
        $exchange->method('getIncrementId')->willReturn('EX-TEST');
        $exchange->method('getOriginalOrderId')->willReturn(50);
        $exchange->method('getStoreId')->willReturn(1);
        $exchange->method('getCustomerId')->willReturn(7);
        $exchange->method('getCurrencyCode')->willReturn('EGP');
        $exchange->method('getBaseCurrencyCode')->willReturn('EGP');
        $exchange->method('getCatalogPricesIncludeTax')
            ->willReturn($catalogPricesIncludeTax);
        $exchange->method('getExchangeStatus')
            ->willReturn(ExchangeStatus::IN_PROGRESS);
        $exchange->method('getReturnStatus')
            ->willReturn(ReturnStatus::ACCEPTED);
        $exchange->method('getReplacementStatus')
            ->willReturn($replacementStatus);
        $exchange->method('getSettlementStatus')
            ->willReturn(SettlementStatus::PENDING);
        $exchange->method('getReplacementAmount')->willReturn($replacementAmount);
        $exchange->method('getShippingAmount')->willReturn($shippingAmount);
        $exchange->method('getFeeAmount')->willReturn('0.0000');

        return $exchange;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(
        int $entityId,
        int $productId,
        ?int $orderItemId = null
    ): array {
        return [
            ReplacementItemInterface::ENTITY_ID => $entityId,
            ReplacementItemInterface::EXCHANGE_ID => 5,
            ReplacementItemInterface::PRODUCT_ID => $productId,
            ReplacementItemInterface::SKU => 'sku-' . $productId,
            ReplacementItemInterface::NAME => 'Product ' . $productId,
            ReplacementItemInterface::QTY => '1.0000',
            ReplacementItemInterface::UNIT_PRICE_AMOUNT => '15.0000',
            ReplacementItemInterface::ROW_TOTAL_AMOUNT => '15.0000',
            ReplacementItemInterface::PRODUCT_OPTIONS_JSON => null,
            ReplacementItemInterface::REPLACEMENT_ORDER_ITEM_ID => $orderItemId,
        ];
    }

    private function orderAddress(
        string $type,
        string $city,
        string $region = 'Cairo',
        ?int $regionId = null,
        ?string $regionCode = null
    ): OrderAddressInterface {
        $address = $this->createMock(OrderAddressInterface::class);
        $address->method('getAddressType')->willReturn($type);
        $address->method('getPrefix')->willReturn(null);
        $address->method('getFirstname')->willReturn('Test');
        $address->method('getMiddlename')->willReturn(null);
        $address->method('getLastname')->willReturn('Customer');
        $address->method('getSuffix')->willReturn(null);
        $address->method('getCompany')->willReturn(null);
        $address->method('getStreet')->willReturn(['1 Main Street']);
        $address->method('getCity')->willReturn($city);
        $address->method('getRegion')->willReturn($region);
        $address->method('getRegionId')->willReturn($regionId);
        $address->method('getRegionCode')->willReturn($regionCode);
        $address->method('getPostcode')->willReturn('11511');
        $address->method('getCountryId')->willReturn('EG');
        $address->method('getTelephone')->willReturn('01000000000');
        $address->method('getFax')->willReturn(null);
        $address->method('getVatId')->willReturn(null);
        $address->method('getEmail')->willReturn(null);

        return $address;
    }

    private function quoteAddress(): QuoteAddress
    {
        $address = $this->getMockBuilder(QuoteAddress::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getRegion', 'getRegionId', 'getRegionCode'])
            ->getMock();
        $address->method('getRegion')->willReturnCallback(
            static fn () => $address->getData('region')
        );
        $address->method('getRegionId')->willReturnCallback(
            static fn () => $address->getData('region_id')
        );
        $address->method('getRegionCode')->willReturnCallback(
            static fn () => $address->getData('region_code')
        );

        return $address;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function modelWithoutConstructor(string $class): object
    {
        /** @var T $model */
        $model = (new \ReflectionClass($class))->newInstanceWithoutConstructor();

        return $model;
    }
}
