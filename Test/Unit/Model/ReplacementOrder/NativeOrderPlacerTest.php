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
use Bonlineco\SalesExchange\Model\Order\FreshOrderLoader;
use Bonlineco\SalesExchange\Model\Payment\Replacement as ReplacementPayment;
use Bonlineco\SalesExchange\Model\ReplacementOrder\ConvertedOrderValidator;
use Bonlineco\SalesExchange\Model\ReplacementOrder\ExecutionContext;
use Bonlineco\SalesExchange\Model\ReplacementOrder\Marker;
use Bonlineco\SalesExchange\Model\ReplacementOrder\NativeOrderPlacer;
use Bonlineco\SalesExchange\Model\ReplacementOrder\NativeOrderValidator;
use Bonlineco\SalesExchange\Model\ReplacementOrder\QuoteValidator;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\PaymentInterface;
use Magento\Quote\Api\Data\PaymentInterfaceFactory;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\ResourceModel\Quote as QuoteResource;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class NativeOrderPlacerTest extends TestCase
{
    private const INTENT_HASH =
        'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testCommitsOnlyAfterCorePersistsQuoteInactive(): void
    {
        $context = new ExecutionContext();
        $quote = $this->inactiveQuote();
        $submittedQuote = $this->inactiveQuote();
        $submittedQuote->setIsActive(true);
        $exchange = $this->exchange();
        $originalOrder = $this->createMock(OrderInterface::class);
        $savedOrder = $this->createStub(OrderInterface::class);
        $savedOrder->method('getEntityId')->willReturn(200);
        $replacementRows = [[
            ReplacementItemInterface::ENTITY_ID => 71,
            ReplacementItemInterface::PRODUCT_ID => 21,
            ReplacementItemInterface::SKU => 'replacement-sku',
            ReplacementItemInterface::NAME => 'Replacement',
            ReplacementItemInterface::QTY => '1.0000',
            ReplacementItemInterface::UNIT_PRICE_AMOUNT => '12999.0000',
            ReplacementItemInterface::ROW_TOTAL_AMOUNT => '12999.0000',
        ]];
        $validator = $this->validator(
            2,
            $context,
            $quote,
            $originalOrder,
            $exchange,
            $replacementRows,
            $submittedQuote
        );
        $convertedValidator = $this->createMock(
            ConvertedOrderValidator::class
        );
        $convertedValidator->expects(self::once())
            ->method('execute')
            ->with($submittedQuote, $savedOrder);
        $nativeValidator = $this->createMock(
            NativeOrderValidator::class
        );
        $snapshotCalls = 0;
        $nativeValidator->expects(self::exactly(2))
            ->method('snapshot')
            ->willReturnCallback(
                static function (
                    OrderInterface $candidate,
                    OrderInterface $actualOriginal,
                    ExchangeInterface $actualExchange,
                    array $actualRows,
                    string $actualIntent,
                    int $actualQuoteId
                ) use (
                    &$snapshotCalls,
                    $savedOrder,
                    $originalOrder,
                    $exchange,
                    $replacementRows
                ): array {
                    ++$snapshotCalls;
                    if ($snapshotCalls === 1) {
                        self::assertSame($savedOrder, $candidate);
                    } else {
                        self::assertNotSame($savedOrder, $candidate);
                        self::assertSame(
                            200,
                            (int)$candidate->getEntityId()
                        );
                    }
                    self::assertSame($originalOrder, $actualOriginal);
                    self::assertSame($exchange, $actualExchange);
                    self::assertSame($replacementRows, $actualRows);
                    self::assertSame(
                        self::INTENT_HASH,
                        $actualIntent
                    );
                    self::assertSame(41, $actualQuoteId);

                    return [
                        'snapshot_hash' => str_repeat('b', 64),
                    ];
                }
            );
        $payment = $this->createMock(PaymentInterface::class);
        $payment->expects(self::once())
            ->method('setMethod')
            ->with(ReplacementPayment::CODE)
            ->willReturnSelf();
        $paymentFactory = $this->paymentFactory($payment);
        $saveStates = [];
        $quoteRepository = $this->createMock(
            CartRepositoryInterface::class
        );
        $quoteRepository->expects(self::once())
            ->method('save')
            ->with($quote)
            ->willReturnCallback(
                static function (Quote $savedQuote) use (
                    &$saveStates
                ): void {
                    $saveStates[] = (bool)$savedQuote->getIsActive();
                }
            );
        $cartManagement = $this->createMock(
            CartManagementInterface::class
        );
        $cartManagement->expects(self::once())
            ->method('placeOrder')
            ->with(41, $payment)
            ->willReturnCallback(
                static function () use (
                    $context,
                    $quote,
                    $submittedQuote,
                    $savedOrder
                ): int {
                    self::assertTrue((bool)$quote->getIsActive());
                    self::assertTrue($context->isTrustedQuote($quote));
                    self::assertTrue(
                        $context->hasPrePlaceOrderValidator()
                    );
                    $context->validateBeforeSubmit($submittedQuote);
                    $context->validateBeforePlace($savedOrder);
                    $context->validateAfterSave($savedOrder);

                    return 200;
                }
            );
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())
            ->method('beginTransaction')
            ->willReturnSelf();
        $this->expectInactiveDurableRow($connection);
        $connection->expects(self::once())
            ->method('commit')
            ->willReturnCallback(
                static function () use (
                    $quote,
                    &$saveStates,
                    $connection
                ): AdapterInterface {
                    self::assertFalse((bool)$quote->getIsActive());
                    self::assertSame([true], $saveStates);

                    return $connection;
                }
            );
        $connection->expects(self::never())->method('rollBack');

        $result = $this->placer(
            $context,
            $validator,
            $cartManagement,
            $quoteRepository,
            $paymentFactory,
            $this->quoteResource($connection),
            $convertedValidator,
            $nativeValidator
        )->execute(
            $quote,
            $originalOrder,
            $exchange,
            $replacementRows,
            self::INTENT_HASH
        );

        self::assertSame(200, $result);
        self::assertSame([true], $saveStates);
        self::assertFalse((bool)$quote->getIsActive());
        self::assertFalse(
            $context->isActiveFor(7, self::INTENT_HASH)
        );
        self::assertFalse($context->isTrustedQuote($quote));
    }

    public function testRollsBackActiveSaveAndClearsContextOnFailure(): void
    {
        $context = new ExecutionContext();
        $quote = $this->inactiveQuote();
        $exchange = $this->exchange();
        $originalOrder = $this->createMock(OrderInterface::class);
        $replacementRows = [[
            ReplacementItemInterface::ENTITY_ID => 71,
            ReplacementItemInterface::PRODUCT_ID => 21,
            ReplacementItemInterface::SKU => 'replacement-sku',
            ReplacementItemInterface::NAME => 'Replacement',
            ReplacementItemInterface::QTY => '1.0000',
            ReplacementItemInterface::UNIT_PRICE_AMOUNT => '12999.0000',
            ReplacementItemInterface::ROW_TOTAL_AMOUNT => '12999.0000',
        ]];
        $validator = $this->validator(
            1,
            $context,
            $quote,
            $originalOrder,
            $exchange,
            $replacementRows
        );
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('setMethod')->willReturnSelf();
        $quoteRepository = $this->createMock(
            CartRepositoryInterface::class
        );
        $quoteRepository->expects(self::once())
            ->method('save')
            ->with($quote)
            ->willReturnCallback(
                static function (Quote $savedQuote): void {
                    self::assertTrue((bool)$savedQuote->getIsActive());
                }
            );
        $cartManagement = $this->createMock(
            CartManagementInterface::class
        );
        $cartManagement->expects(self::once())
            ->method('placeOrder')
            ->willThrowException(
                new \RuntimeException('native place failed')
            );
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())
            ->method('beginTransaction')
            ->willReturnSelf();
        $connection->expects(self::never())->method('commit');
        $connection->expects(self::once())
            ->method('rollBack')
            ->willReturnCallback(
                static function () use (
                    $quote,
                    $connection
                ): AdapterInterface {
                    self::assertFalse((bool)$quote->getIsActive());

                    return $connection;
                }
            );

        try {
            $this->placer(
                $context,
                $validator,
                $cartManagement,
                $quoteRepository,
                $this->paymentFactory($payment),
                $this->quoteResource($connection)
            )->execute(
                $quote,
                $originalOrder,
                $exchange,
                $replacementRows,
                self::INTENT_HASH
            );
            self::fail('The native placement exception must be preserved.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'native place failed',
                $exception->getMessage()
            );
        }

        self::assertFalse((bool)$quote->getIsActive());
        self::assertFalse(
            $context->isActiveFor(7, self::INTENT_HASH)
        );
        self::assertFalse($context->isTrustedQuote($quote));
    }

    public function testDifferentPersistenceAdaptersFailBeforeTransaction(): void
    {
        $context = new ExecutionContext();
        $quote = $this->inactiveQuote();
        $exchange = $this->exchange();
        $originalOrder = $this->createMock(OrderInterface::class);
        $replacementRows = [[
            ReplacementItemInterface::ENTITY_ID => 71,
            ReplacementItemInterface::PRODUCT_ID => 21,
            ReplacementItemInterface::SKU => 'replacement-sku',
            ReplacementItemInterface::NAME => 'Replacement',
            ReplacementItemInterface::QTY => '1.0000',
            ReplacementItemInterface::UNIT_PRICE_AMOUNT => '12999.0000',
            ReplacementItemInterface::ROW_TOTAL_AMOUNT => '12999.0000',
        ]];
        $quoteConnection = $this->createMock(AdapterInterface::class);
        $quoteConnection->expects(self::never())
            ->method('beginTransaction');
        $orderConnection = $this->createMock(AdapterInterface::class);
        $orderResource = $this->createMock(OrderResource::class);
        $orderResource->method('getConnection')
            ->willReturn($orderConnection);
        $cartManagement = $this->createMock(
            CartManagementInterface::class
        );
        $cartManagement->expects(self::never())->method('placeOrder');
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('setMethod')->willReturnSelf();
        $this->expectException(InvariantViolationException::class);
        $this->expectExceptionMessage(
            'Replacement quotes and orders must share one database transaction.'
        );

        $this->placer(
            $context,
            $this->validator(
                1,
                $context,
                $quote,
                $originalOrder,
                $exchange,
                $replacementRows
            ),
            $cartManagement,
            $this->createMock(CartRepositoryInterface::class),
            $this->paymentFactory($payment),
            $this->quoteResource($quoteConnection),
            null,
            null,
            $orderResource
        )->execute(
            $quote,
            $originalOrder,
            $exchange,
            $replacementRows,
            self::INTENT_HASH
        );
    }

    private function placer(
        ExecutionContext $context,
        QuoteValidator $validator,
        CartManagementInterface $cartManagement,
        CartRepositoryInterface $quoteRepository,
        PaymentInterfaceFactory $paymentFactory,
        QuoteResource $quoteResource,
        ?ConvertedOrderValidator $convertedValidator = null,
        ?NativeOrderValidator $nativeOrderValidator = null,
        ?OrderResource $orderResource = null,
        ?FreshOrderLoader $freshOrderLoader = null
    ): NativeOrderPlacer {
        if ($orderResource === null) {
            $orderResource = $this->createMock(OrderResource::class);
            $orderResource->method('getConnection')->willReturn(
                $quoteResource->getConnection()
            );
        }
        if ($freshOrderLoader === null) {
            $persistedOrder = $this->createStub(OrderInterface::class);
            $persistedOrder->method('getEntityId')->willReturn(200);
            $freshOrderLoader = $this->createMock(
                FreshOrderLoader::class
            );
            $freshOrderLoader->method('execute')
                ->with(200)
                ->willReturn($persistedOrder);
        }

        return new NativeOrderPlacer(
            $context,
            $validator,
            $cartManagement,
            $quoteRepository,
            $paymentFactory,
            $quoteResource,
            $orderResource,
            $freshOrderLoader,
            $convertedValidator
                ?? $this->createMock(ConvertedOrderValidator::class),
            $nativeOrderValidator
                ?? $this->createMock(NativeOrderValidator::class)
        );
    }

    private function inactiveQuote(): Quote
    {
        /** @var Quote $quote */
        $quote = (new \ReflectionClass(Quote::class))
            ->newInstanceWithoutConstructor();
        $quote->setId(41)
            ->setIsActive(false)
            ->setData(Marker::EXCHANGE_ID, 7)
            ->setData(Marker::INTENT_HASH, self::INTENT_HASH);

        return $quote;
    }

    /**
     * @param array<int, array<string, mixed>> $replacementRows
     */
    private function validator(
        int $expectedCalls,
        ExecutionContext $context,
        Quote $quote,
        OrderInterface $originalOrder,
        ExchangeInterface $exchange,
        array $replacementRows,
        ?Quote $submittedQuote = null
    ): QuoteValidator {
        $calls = 0;
        $validator = $this->createMock(QuoteValidator::class);
        $validator->expects(self::exactly($expectedCalls))
            ->method('assertPrepared')
            ->willReturnCallback(
                static function (
                    Quote $actualQuote,
                    OrderInterface $actualOriginalOrder,
                    ExchangeInterface $actualExchange,
                    array $actualRows,
                    string $actualIntentHash,
                    bool $expectedActive = false
                ) use (
                    &$calls,
                    $context,
                    $quote,
                    $originalOrder,
                    $exchange,
                    $replacementRows,
                    $submittedQuote
                ): void {
                    $expectedQuote = $calls === 0
                        ? $quote
                        : ($submittedQuote ?? $quote);
                    self::assertSame($expectedQuote, $actualQuote);
                    self::assertSame(
                        $originalOrder,
                        $actualOriginalOrder
                    );
                    self::assertSame($exchange, $actualExchange);
                    self::assertSame($replacementRows, $actualRows);
                    self::assertSame(
                        self::INTENT_HASH,
                        $actualIntentHash
                    );
                    ++$calls;
                    self::assertSame(
                        $expectedActive,
                        (bool)$actualQuote->getIsActive()
                    );
                    self::assertSame(
                        $calls > 1,
                        $context->isTrustedQuote($actualQuote)
                    );
                }
            );

        return $validator;
    }

    private function exchange(): ExchangeInterface
    {
        $exchange = $this->createMock(ExchangeInterface::class);
        $exchange->method('getEntityId')->willReturn(7);

        return $exchange;
    }

    /**
     * @return PaymentInterfaceFactory&MockObject
     */
    private function paymentFactory(
        PaymentInterface $payment
    ): PaymentInterfaceFactory {
        $factory = $this->getMockBuilder(PaymentInterfaceFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $factory->method('create')->willReturn($payment);

        return $factory;
    }

    private function quoteResource(
        AdapterInterface $connection
    ): QuoteResource {
        $resource = $this->createMock(QuoteResource::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getMainTable')->willReturn('quote');

        return $resource;
    }

    private function expectInactiveDurableRow(
        AdapterInterface $connection
    ): void {
        $select = $this->getMockBuilder(Select::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['from', 'where', 'limit'])
            ->getMock();
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        $connection->expects(self::once())
            ->method('select')
            ->willReturn($select);
        $connection->expects(self::once())
            ->method('fetchRow')
            ->with($select)
            ->willReturn([
                'entity_id' => 41,
                'is_active' => 0,
                Marker::EXCHANGE_ID => 7,
                Marker::INTENT_HASH => self::INTENT_HASH,
            ]);
    }
}
