<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Unit\Model\ReplacementOrder;

use Bonlineco\SalesExchange\Api\Data\DocumentLinkInterface;
use Bonlineco\SalesExchange\Api\Data\ExchangeInterface;
use Bonlineco\SalesExchange\Api\Data\ReplacementItemInterface;
use Bonlineco\SalesExchange\Api\DocumentType;
use Bonlineco\SalesExchange\Api\ExchangeRepositoryInterface;
use Bonlineco\SalesExchange\Api\Status\ExchangeStatus;
use Bonlineco\SalesExchange\Api\Status\ReplacementStatus;
use Bonlineco\SalesExchange\Api\Status\ReturnStatus;
use Bonlineco\SalesExchange\Api\Status\SettlementStatus;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\BalanceCalculator;
use Bonlineco\SalesExchange\Model\Creditmemo\ReturnCreditProjection;
use Bonlineco\SalesExchange\Model\Exchange;
use Bonlineco\SalesExchange\Model\ExchangeFactory;
use Bonlineco\SalesExchange\Model\FinancialAggregateCalculator;
use Bonlineco\SalesExchange\Model\History;
use Bonlineco\SalesExchange\Model\HistoryFactory;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Bonlineco\SalesExchange\Model\ReplacementOrder\CancelReplacementIntent;
use Bonlineco\SalesExchange\Model\ReplacementOrder\IntentHasher;
use Bonlineco\SalesExchange\Model\ReplacementOrder\NativeOrderResolver;
use Bonlineco\SalesExchange\Model\ReplacementOrder\PreparedQuoteLookup;
use Bonlineco\SalesExchange\Model\ResourceModel\DocumentLink as DocumentLinkResource;
use Bonlineco\SalesExchange\Model\ResourceModel\Exchange as ExchangeResource;
use Bonlineco\SalesExchange\Model\ResourceModel\History as HistoryResource;
use Bonlineco\SalesExchange\Model\ResourceModel\ReplacementItem as ReplacementItemResource;
use Bonlineco\SalesExchange\Model\ResourceModel\ReturnItem as ReturnItemResource;
use Bonlineco\SalesExchange\Model\StateTransitionGuard;
use Bonlineco\SalesExchange\Model\VersionGuard;
use Bonlineco\SalesExchange\Model\WorkflowCoordinator;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\OrderMutexInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Verify the pre-native replacement compensation is atomic and replay-safe.
 */
class CancelReplacementIntentTest extends TestCase
{
    private const INTENT_HASH =
        'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private int $exchangeSaveCount = 0;

    private int $historySaveCount = 0;

    private int $quoteSaveCount = 0;

    private ?Exchange $savedExchange = null;

    private ?History $savedHistory = null;

    private ?ExchangeInterface $intentExchange = null;

    public function testReadyCancellationPreservesApprovedSnapshotAndDeactivatesQuote(): void
    {
        $quote = $this->quote(41, true);
        $subject = $this->subject(
            $this->exchangeRow(ReplacementStatus::READY, 7, '100.0000'),
            $this->replacementRows(null),
            [],
            $quote
        );

        $result = $subject->execute(7, 7, 9, '  operations approved  ');

        self::assertSame(1, $this->exchangeSaveCount);
        self::assertSame(1, $this->historySaveCount);
        self::assertSame(1, $this->quoteSaveCount);
        self::assertFalse((bool)$quote->getIsActive());
        self::assertSame(ReplacementStatus::CANCELLED, $result->getReplacementStatus());
        self::assertSame('100.0000', $result->getReplacementAmount());
        self::assertSame('0.0000', $result->getShippingAmount());
        self::assertSame('0.0000', $result->getFeeAmount());
        self::assertSame('0.0000', $result->getNativeReplacementAmount());
        self::assertSame('0.0000', $result->getBaseNativeReplacementAmount());
        self::assertSame('-80.0000', $result->getBalanceAmount());
        self::assertSame(8, $result->getVersion());
        self::assertSame(
            'replacement_intent_cancelled',
            $this->savedHistory?->getAction()
        );
        self::assertSame('operations approved', $this->savedHistory?->getComment());
        self::assertStringContainsString(
            'quote=41',
            (string)$this->savedHistory?->getToValue()
        );
    }

    public function testPendingCancellationKeepsAggregateAtZeroButHashesApprovedRows(): void
    {
        $subject = $this->subject(
            $this->exchangeRow(ReplacementStatus::PENDING, 3, '0.0000'),
            $this->replacementRows(null),
            [],
            null
        );

        $result = $subject->execute(7, 3, 9);

        self::assertSame('100.0000', $this->intentExchange?->getReplacementAmount());
        self::assertSame(ReplacementStatus::CANCELLED, $result->getReplacementStatus());
        self::assertSame('0.0000', $result->getReplacementAmount());
        self::assertSame('0.0000', $result->getShippingAmount());
        self::assertSame('-80.0000', $result->getBalanceAmount());
        self::assertSame(4, $result->getVersion());
    }

    public function testStaleCancelledReplayDeactivatesQuoteWithoutNewAggregateWrite(): void
    {
        $quote = $this->quote(41, true);
        $subject = $this->subject(
            $this->exchangeRow(ReplacementStatus::CANCELLED, 9, '100.0000'),
            $this->replacementRows(null),
            [],
            $quote
        );

        $result = $subject->execute(7, 1, 9);

        self::assertSame(0, $this->exchangeSaveCount);
        self::assertSame(0, $this->historySaveCount);
        self::assertSame(1, $this->quoteSaveCount);
        self::assertFalse((bool)$quote->getIsActive());
        self::assertSame(9, $result->getVersion());
        self::assertSame(ReplacementStatus::CANCELLED, $result->getReplacementStatus());
    }

    public function testNativeOrderResolvedByQuotePreventsCancellation(): void
    {
        $nativeOrder = $this->createMock(OrderInterface::class);
        $subject = $this->subject(
            $this->exchangeRow(ReplacementStatus::READY, 7, '100.0000'),
            $this->replacementRows(null),
            [],
            $this->quote(41, false),
            $nativeOrder
        );

        $this->expectException(InvariantViolationException::class);
        try {
            $subject->execute(7, 7, 9);
        } finally {
            self::assertSame(0, $this->exchangeSaveCount);
            self::assertSame(0, $this->historySaveCount);
            self::assertSame(0, $this->quoteSaveCount);
        }
    }

    public function testOrderDocumentLinkPreventsCancellation(): void
    {
        $subject = $this->subject(
            $this->exchangeRow(ReplacementStatus::READY, 7, '100.0000'),
            $this->replacementRows(null),
            [[DocumentLinkInterface::DOCUMENT_TYPE => DocumentType::ORDER]]
        );

        $this->expectException(InvariantViolationException::class);
        $subject->execute(7, 7, 9);
    }

    public function testReplacementOrderItemLinkPreventsCancellation(): void
    {
        $subject = $this->subject(
            $this->exchangeRow(ReplacementStatus::READY, 7, '100.0000'),
            $this->replacementRows(501),
            []
        );

        $this->expectException(InvariantViolationException::class);
        $subject->execute(7, 7, 9);
    }

    public function testCancelledReplayRejectsNativeTotals(): void
    {
        $row = $this->exchangeRow(
            ReplacementStatus::CANCELLED,
            9,
            '100.0000'
        );
        $row[ExchangeInterface::BASE_NATIVE_REPLACEMENT_AMOUNT] = '1.0000';
        $subject = $this->subject($row, $this->replacementRows(null), []);

        $this->expectException(InvariantViolationException::class);
        $subject->execute(7, 1, 9);
    }

    public function testInputValidationRunsBeforeDependencies(): void
    {
        $subject = (new \ReflectionClass(CancelReplacementIntent::class))
            ->newInstanceWithoutConstructor();

        $this->expectException(InvariantViolationException::class);
        $subject->execute(7, 1, 0);
    }

    public function testCommentLimitIsEnforcedByTheService(): void
    {
        $subject = (new \ReflectionClass(CancelReplacementIntent::class))
            ->newInstanceWithoutConstructor();

        $this->expectException(InvariantViolationException::class);
        $subject->execute(7, 1, 9, str_repeat('x', 1001));
    }

    /**
     * @param array<string, mixed> $exchangeRow
     * @param array<int, array<string, mixed>> $replacementRows
     * @param array<int, array<string, mixed>> $documentRows
     */
    private function subject(
        array $exchangeRow,
        array $replacementRows,
        array $documentRows,
        ?Quote $quote = null,
        ?OrderInterface $nativeOrder = null
    ): CancelReplacementIntent {
        $subject = (new \ReflectionClass(CancelReplacementIntent::class))
            ->newInstanceWithoutConstructor();
        $initial = $this->createMock(ExchangeInterface::class);
        $initial->method('getOriginalOrderId')->willReturn(100);
        $exchangeRepository = $this->createMock(
            ExchangeRepositoryInterface::class
        );
        $exchangeRepository->method('getById')->with(7)->willReturn($initial);
        $this->setProperty($subject, 'exchangeRepository', $exchangeRepository);

        $mutex = $this->createMock(OrderMutexInterface::class);
        $mutex->method('execute')->willReturnCallback(
            static function (
                int $orderId,
                callable $callable,
                array $args
            ) {
                TestCase::assertSame(100, $orderId);

                return $callable(...$args);
            }
        );
        $this->setProperty($subject, 'orderMutex', $mutex);

        $exchangeResource = $this->createMock(ExchangeResource::class);
        $exchangeResource->method('getDataForUpdate')
            ->with(7)
            ->willReturn($exchangeRow);
        $exchangeResource->method('save')->willReturnCallback(
            function (Exchange $exchange): void {
                $this->exchangeSaveCount++;
                $this->savedExchange = $exchange;
            }
        );
        $this->setProperty($subject, 'exchangeResource', $exchangeResource);
        $this->setProperty(
            $subject,
            'exchangeFactory',
            $this->factoryMock(
                ExchangeFactory::class,
                static fn (): Exchange => (new \ReflectionClass(
                    Exchange::class
                ))->newInstanceWithoutConstructor()
            )
        );

        $replacementResource = $this->createMock(
            ReplacementItemResource::class
        );
        $replacementResource->method('getRowsByExchangeIdForUpdate')
            ->with(7)
            ->willReturn($replacementRows);
        $this->setProperty(
            $subject,
            'replacementItemResource',
            $replacementResource
        );
        $returnResource = $this->createMock(ReturnItemResource::class);
        $returnResource->method('getRowsByExchangeIdForUpdate')
            ->with(7)
            ->willReturn([]);
        $this->setProperty($subject, 'returnItemResource', $returnResource);
        $documentResource = $this->createMock(DocumentLinkResource::class);
        $documentResource->method('getRowsByExchangeIdForUpdate')
            ->with(7)
            ->willReturn($documentRows);
        $this->setProperty(
            $subject,
            'documentLinkResource',
            $documentResource
        );

        $aggregate = $this->createMock(FinancialAggregateCalculator::class);
        $aggregate->method('getReplacementAmount')
            ->with($replacementRows)
            ->willReturn('100.0000');
        $this->setProperty($subject, 'aggregateCalculator', $aggregate);
        $hasher = $this->createMock(IntentHasher::class);
        $hasher->method('execute')->willReturnCallback(
            function (
                ExchangeInterface $exchange,
                array $rows
            ) use ($replacementRows): string {
                self::assertSame($replacementRows, $rows);
                $this->intentExchange = $exchange;

                return self::INTENT_HASH;
            }
        );
        $this->setProperty($subject, 'intentHasher', $hasher);
        $lookup = $this->createMock(PreparedQuoteLookup::class);
        $lookup->method('find')
            ->with(7, self::INTENT_HASH)
            ->willReturn($quote);
        $this->setProperty($subject, 'preparedQuoteLookup', $lookup);
        $resolver = $this->createMock(NativeOrderResolver::class);
        $resolver->method('find')
            ->with(
                7,
                self::INTENT_HASH,
                $quote === null ? null : (int)$quote->getId()
            )->willReturn($nativeOrder);
        $this->setProperty($subject, 'nativeOrderResolver', $resolver);

        $quoteRepository = $this->createMock(CartRepositoryInterface::class);
        $quoteRepository->method('save')->willReturnCallback(
            function (Quote $savedQuote): void {
                self::assertFalse((bool)$savedQuote->getIsActive());
                $this->quoteSaveCount++;
            }
        );
        $quoteRepository->method('get')->willReturnCallback(
            static function (int $quoteId) use ($quote): Quote {
                TestCase::assertNotNull($quote);
                TestCase::assertSame($quoteId, (int)$quote->getId());

                return $quote;
            }
        );
        $this->setProperty($subject, 'quoteRepository', $quoteRepository);
        $this->setProperty(
            $subject,
            'transitionGuard',
            new StateTransitionGuard()
        );
        $this->setProperty(
            $subject,
            'workflowCoordinator',
            new WorkflowCoordinator(
                new DecimalMath(),
                new DecimalMath(4, 12)
            )
        );
        $this->setProperty($subject, 'versionGuard', new VersionGuard());
        $returnProjection = $this->createMock(
            ReturnCreditProjection::class
        );
        $returnProjection->method('execute')
            ->willReturn('80.0000');
        $this->setProperty(
            $subject,
            'returnCreditProjection',
            $returnProjection
        );
        $moneyMath = new DecimalMath();
        $this->setProperty(
            $subject,
            'balanceCalculator',
            new BalanceCalculator($moneyMath)
        );
        $this->setProperty($subject, 'moneyMath', $moneyMath);

        $historyResource = $this->createMock(HistoryResource::class);
        $historyResource->method('save')->willReturnCallback(
            function (History $history): void {
                $this->historySaveCount++;
                $this->savedHistory = $history;
            }
        );
        $this->setProperty($subject, 'historyResource', $historyResource);
        $this->setProperty(
            $subject,
            'historyFactory',
            $this->factoryMock(
                HistoryFactory::class,
                static fn (): History => (new \ReflectionClass(
                    History::class
                ))->newInstanceWithoutConstructor()
            )
        );

        return $subject;
    }

    /**
     * @return array<string, mixed>
     */
    private function exchangeRow(
        string $status,
        int $version,
        string $replacementAmount
    ): array {
        return [
            ExchangeInterface::ENTITY_ID => 7,
            ExchangeInterface::INCREMENT_ID => 'EX-7',
            ExchangeInterface::ORIGINAL_ORDER_ID => 100,
            ExchangeInterface::STORE_ID => 1,
            ExchangeInterface::CUSTOMER_ID => 9,
            ExchangeInterface::CURRENCY_CODE => 'EGP',
            ExchangeInterface::BASE_CURRENCY_CODE => 'EGP',
            ExchangeInterface::EXCHANGE_STATUS => ExchangeStatus::IN_PROGRESS,
            ExchangeInterface::RETURN_STATUS => ReturnStatus::ACCEPTED,
            ExchangeInterface::REPLACEMENT_STATUS => $status,
            ExchangeInterface::SETTLEMENT_STATUS => SettlementStatus::PENDING,
            ExchangeInterface::RETURN_CREDIT_AMOUNT => '80.0000',
            ExchangeInterface::NATIVE_RETURN_CREDIT_AMOUNT => '80.0000',
            ExchangeInterface::BASE_NATIVE_RETURN_CREDIT_AMOUNT => '80.0000',
            ExchangeInterface::REPLACEMENT_AMOUNT => $replacementAmount,
            ExchangeInterface::SHIPPING_AMOUNT => '0.0000',
            ExchangeInterface::FEE_AMOUNT => '0.0000',
            ExchangeInterface::NATIVE_REPLACEMENT_AMOUNT => '0.0000',
            ExchangeInterface::BASE_NATIVE_REPLACEMENT_AMOUNT => '0.0000',
            ExchangeInterface::BALANCE_AMOUNT => '-80.0000',
            ExchangeInterface::VERSION => $version,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function replacementRows(?int $orderItemId): array
    {
        return [[
            ReplacementItemInterface::ENTITY_ID => 71,
            ReplacementItemInterface::EXCHANGE_ID => 7,
            ReplacementItemInterface::PRODUCT_ID => 21,
            ReplacementItemInterface::SKU => 'replacement-sku',
            ReplacementItemInterface::NAME => 'Replacement',
            ReplacementItemInterface::QTY => '1.0000',
            ReplacementItemInterface::UNIT_PRICE_AMOUNT => '100.0000',
            ReplacementItemInterface::ROW_TOTAL_AMOUNT => '100.0000',
            ReplacementItemInterface::PRODUCT_OPTIONS_JSON => null,
            ReplacementItemInterface::REPLACEMENT_ORDER_ITEM_ID =>
                $orderItemId,
            ReplacementItemInterface::VERSION => 1,
        ]];
    }

    private function quote(int $quoteId, bool $active): Quote
    {
        $quote = (new \ReflectionClass(Quote::class))
            ->newInstanceWithoutConstructor();
        $quote->setId($quoteId)->setIsActive($active);

        return $quote;
    }

    /**
     * @template T of object
     * @param class-string<T> $className
     * @param callable(): T $create
     * @return T&MockObject
     */
    private function factoryMock(string $className, callable $create): object
    {
        $factory = $this->getMockBuilder($className)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $factory->method('create')->willReturnCallback($create);

        return $factory;
    }

    private function setProperty(
        CancelReplacementIntent $subject,
        string $name,
        object $value
    ): void {
        (new \ReflectionProperty(CancelReplacementIntent::class, $name))
            ->setValue($subject, $value);
    }
}
