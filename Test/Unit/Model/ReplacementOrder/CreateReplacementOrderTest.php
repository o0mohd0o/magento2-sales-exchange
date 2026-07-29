<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Unit\Model\ReplacementOrder;

use Bonlineco\SalesExchange\Api\BalanceCalculatorInterface;
use Bonlineco\SalesExchange\Api\ConfigInterface;
use Bonlineco\SalesExchange\Api\Data\DocumentLinkInterface;
use Bonlineco\SalesExchange\Api\Data\ExchangeInterface;
use Bonlineco\SalesExchange\Api\Data\ReplacementItemInterface;
use Bonlineco\SalesExchange\Api\DocumentType;
use Bonlineco\SalesExchange\Api\ExchangeRepositoryInterface;
use Bonlineco\SalesExchange\Api\Status\ExchangeStatus;
use Bonlineco\SalesExchange\Api\Status\ReplacementStatus;
use Bonlineco\SalesExchange\Api\Status\ReturnStatus;
use Bonlineco\SalesExchange\Api\Status\SettlementStatus;
use Bonlineco\SalesExchange\Model\Creditmemo\ReturnCreditProjection;
use Bonlineco\SalesExchange\Model\DocumentLink;
use Bonlineco\SalesExchange\Model\DocumentLinkFactory;
use Bonlineco\SalesExchange\Model\DocumentLinkWriter;
use Bonlineco\SalesExchange\Model\Exchange;
use Bonlineco\SalesExchange\Model\ExchangeFactory;
use Bonlineco\SalesExchange\Model\FinancialAggregateCalculator;
use Bonlineco\SalesExchange\Model\History;
use Bonlineco\SalesExchange\Model\HistoryFactory;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Bonlineco\SalesExchange\Model\ReplacementItem;
use Bonlineco\SalesExchange\Model\ReplacementItemFactory;
use Bonlineco\SalesExchange\Model\ReplacementOrder\CreateReplacementOrder;
use Bonlineco\SalesExchange\Model\ReplacementOrder\IntentHasher;
use Bonlineco\SalesExchange\Model\ReplacementOrder\NativeOrderPlacer;
use Bonlineco\SalesExchange\Model\ReplacementOrder\NativeOrderResolver;
use Bonlineco\SalesExchange\Model\ReplacementOrder\NativeOrderValidator;
use Bonlineco\SalesExchange\Model\ReplacementOrder\PreparedQuoteLookup;
use Bonlineco\SalesExchange\Model\ReplacementOrder\QuotePreparer;
use Bonlineco\SalesExchange\Model\ResourceModel\DocumentLink as DocumentLinkResource;
use Bonlineco\SalesExchange\Model\ResourceModel\Exchange as ExchangeResource;
use Bonlineco\SalesExchange\Model\ResourceModel\History as HistoryResource;
use Bonlineco\SalesExchange\Model\ResourceModel\ReplacementItem as ReplacementItemResource;
use Bonlineco\SalesExchange\Model\ResourceModel\ReturnItem as ReturnItemResource;
use Bonlineco\SalesExchange\Model\VersionGuard;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderMutexInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CreateReplacementOrderTest extends TestCase
{
    private const INTENT_HASH =
        'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /**
     * Native order mutexes currently held by the unit-test harness.
     *
     * @var int[]
     */
    private array $heldOrderLocks = [];

    private int $outerOrderLockGeneration = 0;

    private ?int $placementOrderLockGeneration = null;

    public function testPhpErrorIsLoggedAndWrappedWithoutMaskingTypeError(): void
    {
        $command = $this->commandWithoutConstructor();
        $initial = $this->createMock(ExchangeInterface::class);
        $initial->method('getOriginalOrderId')->willReturn(100);
        $repository = $this->createMock(ExchangeRepositoryInterface::class);
        $repository->method('getById')->willReturn($initial);
        $this->setProperty($command, 'exchangeRepository', $repository);

        $error = new \Error('quote payment failed');
        $mutex = $this->createMock(OrderMutexInterface::class);
        $mutex->expects(self::once())
            ->method('execute')
            ->willReturnCallback(
                static function () use ($error): void {
                    throw $error;
                }
            );
        $this->setProperty($command, 'orderMutex', $mutex);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('critical')
            ->with(
                'Unexpected exchange replacement order failure.',
                self::callback(
                    static fn (array $context): bool =>
                        ($context['exception'] ?? null) === $error
                        && ($context['exchange_id'] ?? null) === 7
                )
            );
        $this->setProperty($command, 'logger', $logger);

        try {
            $command->execute(7, 1, 5);
            self::fail('The unexpected PHP error must be wrapped.');
        } catch (CouldNotSaveException $exception) {
            self::assertSame(
                'The exchange replacement order could not be created.',
                $exception->getMessage()
            );
            self::assertInstanceOf(
                \RuntimeException::class,
                $exception->getPrevious()
            );
            self::assertSame(
                $error,
                $exception->getPrevious()?->getPrevious()
            );
        }
    }

    /**
     * @dataProvider oneClickSagaProvider
     */
    #[DataProvider('oneClickSagaProvider')]
    public function testOneClickSagaPreparesPlacesAndReconcilesExactlyOnce(
        string $initialStatus
    ): void
    {
        $recoverCommittedOrder = $initialStatus !== ReplacementStatus::PENDING;
        $preserveFulfillment = in_array(
            $initialStatus,
            [ReplacementStatus::SHIPPED, ReplacementStatus::DELIVERED],
            true
        );
        $command = $this->commandWithoutConstructor();
        $exchangeRow = $this->exchangeRow(
            $initialStatus,
            $recoverCommittedOrder ? 2 : 1
        );
        if ($recoverCommittedOrder) {
            $exchangeRow[ExchangeInterface::REPLACEMENT_AMOUNT] =
                '100.0000';
        }
        if ($preserveFulfillment) {
            $exchangeRow[ExchangeInterface::NATIVE_REPLACEMENT_AMOUNT] =
                '114.0000';
            $exchangeRow[
                ExchangeInterface::BASE_NATIVE_REPLACEMENT_AMOUNT
            ] = '114.0000';
            $exchangeRow[ExchangeInterface::BALANCE_AMOUNT] = '34.0000';
        }
        if ($initialStatus === ReplacementStatus::DELIVERED) {
            $exchangeRow[ExchangeInterface::EXCHANGE_STATUS] =
                ExchangeStatus::COMPLETED;
            $exchangeRow[ExchangeInterface::SETTLEMENT_STATUS] =
                SettlementStatus::BALANCED;
        }
        $replacementRows = [$this->replacementRow(null)];
        $quote = $this->modelWithoutConstructor(Quote::class);
        $quote->setId(41);
        $original = $this->order(100, '000000100');
        $native = $this->order(200, '000000200');
        $native->setQuoteId(41)->setStatus('pending');

        $this->wireInitialReadAndMutex($command, $exchangeRow);
        $exchangeResource = $this->createMock(ExchangeResource::class);
        $exchangeResource->method('getDataForUpdate')
            ->willReturnCallback(
                static function () use (&$exchangeRow): array {
                    return $exchangeRow;
                }
            );
        $exchangeResource->method('save')->willReturnCallback(
            function (Exchange $exchange) use (&$exchangeRow): void {
                if ($exchange->getReplacementStatus()
                    === ReplacementStatus::ORDERED
                ) {
                    $this->assertReplacementOrderLocked();
                } else {
                    self::assertSame([100], $this->heldOrderLocks);
                }
                $exchangeRow = $exchange->getData();
            }
        );
        $this->setProperty($command, 'exchangeResource', $exchangeResource);
        $this->setProperty(
            $command,
            'exchangeFactory',
            $this->factoryMock(
                ExchangeFactory::class,
                static fn (): Exchange =>
                    (new \ReflectionClass(Exchange::class))
                        ->newInstanceWithoutConstructor()
            )
        );

        $replacementResource = $this->createMock(
            ReplacementItemResource::class
        );
        $replacementResource->method('getRowsByExchangeIdForUpdate')
            ->willReturnCallback(
                static function () use (&$replacementRows): array {
                    return $replacementRows;
                }
            );
        $replacementResource->method('save')->willReturnCallback(
            function (ReplacementItem $item) use (
                &$replacementRows
            ): void {
                $this->assertReplacementOrderLocked();
                $replacementRows[0] = $item->getData();
            }
        );
        $this->setProperty(
            $command,
            'replacementItemResource',
            $replacementResource
        );
        $this->setProperty(
            $command,
            'replacementItemFactory',
            $this->factoryMock(
                ReplacementItemFactory::class,
                static fn (): ReplacementItem =>
                    (new \ReflectionClass(ReplacementItem::class))
                        ->newInstanceWithoutConstructor()
            )
        );

        $aggregate = $this->createMock(FinancialAggregateCalculator::class);
        $aggregate->method('getReplacementAmount')
            ->willReturn('100.0000');
        $this->setProperty($command, 'aggregateCalculator', $aggregate);
        $hasher = $this->createMock(IntentHasher::class);
        $hasher->method('execute')->willReturn(self::INTENT_HASH);
        $this->setProperty($command, 'intentHasher', $hasher);
        $config = $this->createMock(ConfigInterface::class);
        if ($recoverCommittedOrder) {
            $config->expects(self::never())->method('isEnabled');
        } else {
            $config->method('isEnabled')->willReturn(true);
        }
        $this->setProperty($command, 'config', $config);
        $this->setProperty($command, 'moneyMath', new DecimalMath());
        $this->setProperty($command, 'versionGuard', new VersionGuard());

        $documentResource = $this->createMock(DocumentLinkResource::class);
        $documentResource->method('getByOperationKeyForUpdate')
            ->willReturn(null);
        $this->setProperty(
            $command,
            'documentLinkResource',
            $documentResource
        );
        $preparedLookup = $this->createMock(PreparedQuoteLookup::class);
        $preparedLookup->method('find')->willReturn(
            ...($recoverCommittedOrder
                ? [$quote, $quote]
                : [null, $quote, $quote])
        );
        $this->setProperty(
            $command,
            'preparedQuoteLookup',
            $preparedLookup
        );
        $resolver = $this->createMock(NativeOrderResolver::class);
        $resolver->method('find')->willReturn(
            ...($recoverCommittedOrder
                ? [$native, $native, $native]
                : [null, null, $native, $native])
        );
        $this->setProperty($command, 'nativeOrderResolver', $resolver);

        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->method('get')->willReturn($original);
        $this->setProperty($command, 'orderRepository', $orderRepository);
        $quotePreparer = $this->createMock(QuotePreparer::class);
        if ($recoverCommittedOrder) {
            $quotePreparer->expects(self::never())->method('execute');
        } else {
            $quotePreparer->expects(self::once())
                ->method('execute')
                ->willReturn($quote);
        }
        $this->setProperty($command, 'quotePreparer', $quotePreparer);
        $placer = $this->createMock(NativeOrderPlacer::class);
        if ($recoverCommittedOrder) {
            $placer->expects(self::never())->method('execute');
        } else {
            $placer->expects(self::once())
                ->method('execute')
                ->willReturnCallback(function (): int {
                    self::assertSame([100], $this->heldOrderLocks);
                    $this->placementOrderLockGeneration =
                        $this->outerOrderLockGeneration;

                    return 200;
                });
        }
        $this->setProperty($command, 'nativeOrderPlacer', $placer);

        $returnResource = $this->createMock(ReturnItemResource::class);
        $returnResource->method('getRowsByExchangeIdForUpdate')
            ->willReturn([]);
        $this->setProperty(
            $command,
            'returnItemResource',
            $returnResource
        );
        $returnProjection = $this->createMock(ReturnCreditProjection::class);
        $returnProjection->method('execute')->willReturn('80.0000');
        $this->setProperty(
            $command,
            'returnCreditProjection',
            $returnProjection
        );
        $balance = $this->createMock(BalanceCalculatorInterface::class);
        $balance->method('execute')->willReturn(
            ...($recoverCommittedOrder
                ? ['34.0000']
                : ['20.0000', '34.0000'])
        );
        $this->setProperty($command, 'balanceCalculator', $balance);

        $historyResource = $this->createMock(HistoryResource::class);
        $historyResource->expects(
            self::exactly(
                $preserveFulfillment
                    ? 0
                    : ($recoverCommittedOrder ? 1 : 2)
            )
        )->method('save')->willReturnCallback(
            function (History $history): void {
                if ($history->getAction()
                    === 'native_replacement_order_reconciled'
                ) {
                    $this->assertReplacementOrderLocked();
                } else {
                    self::assertSame([100], $this->heldOrderLocks);
                }
            }
        );
        $this->setProperty($command, 'historyResource', $historyResource);
        $this->setProperty(
            $command,
            'historyFactory',
            $this->factoryMock(
                HistoryFactory::class,
                static fn (): History =>
                    (new \ReflectionClass(History::class))
                        ->newInstanceWithoutConstructor()
            )
        );

        $snapshot = $this->snapshot();
        $validator = $this->createMock(NativeOrderValidator::class);
        $validator->expects(self::exactly(2))
            ->method('snapshot')
            ->willReturnCallback(function () use ($snapshot): array {
                $this->assertReplacementOrderLocked();

                return $snapshot;
            });
        $this->setProperty($command, 'nativeOrderValidator', $validator);
        $linkFactory = $this->factoryMock(
            DocumentLinkFactory::class,
            static fn (): DocumentLink =>
                (new \ReflectionClass(DocumentLink::class))
                    ->newInstanceWithoutConstructor()
        );
        $this->setProperty($command, 'documentLinkFactory', $linkFactory);
        $writer = $this->createMock(DocumentLinkWriter::class);
        $writer->method('append')->willReturnCallback(
            function (DocumentLinkInterface $link): DocumentLinkInterface {
                $this->assertReplacementOrderLocked();
                if ($this->placementOrderLockGeneration !== null) {
                    self::assertSame(
                        $this->placementOrderLockGeneration,
                        $this->outerOrderLockGeneration,
                        'Placement and reconciliation must share one outer original-order transaction.'
                    );
                }
                $link->setEntityId(1);

                return $link;
            }
        );
        $this->setProperty($command, 'documentLinkWriter', $writer);
        $events = $this->createMock(ManagerInterface::class);
        $events->expects(self::once())
            ->method('dispatch')
            ->with(
                'bonlineco_sales_exchange_replacement_order_created',
                self::arrayHasKey('order')
            );
        $this->setProperty($command, 'eventManager', $events);
        $this->setProperty(
            $command,
            'logger',
            $this->createMock(LoggerInterface::class)
        );

        $link = $command->execute(7, 1, 5, 'approved');

        self::assertSame(DocumentType::ORDER, $link->getDocumentType());
        self::assertSame(200, $link->getDocumentId());
        self::assertSame(
            $preserveFulfillment
                ? $initialStatus
                : ReplacementStatus::ORDERED,
            $exchangeRow[ExchangeInterface::REPLACEMENT_STATUS]
        );
        self::assertSame(
            '114.0000',
            $exchangeRow[ExchangeInterface::NATIVE_REPLACEMENT_AMOUNT]
        );
        self::assertSame(
            501,
            $replacementRows[0][
                ReplacementItemInterface::REPLACEMENT_ORDER_ITEM_ID
            ]
        );
        self::assertSame([], $this->heldOrderLocks);
        if (!$recoverCommittedOrder) {
            self::assertSame(
                $this->placementOrderLockGeneration,
                $this->outerOrderLockGeneration
            );
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function oneClickSagaProvider(): array
    {
        return [
            'fresh pending replacement' => [ReplacementStatus::PENDING],
            'disabled-config ready committed-order recovery' => [
                ReplacementStatus::READY,
            ],
            'disabled-config shipped partial recovery' => [
                ReplacementStatus::SHIPPED,
            ],
            'disabled-config delivered partial recovery' => [
                ReplacementStatus::DELIVERED,
            ],
        ];
    }

    public function testExactReadyQuoteReplayAcceptsOriginalVersion(): void
    {
        $command = $this->commandWithoutConstructor();
        $exchangeRow = $this->exchangeRow(ReplacementStatus::READY, 2);
        $exchangeRow[ExchangeInterface::REPLACEMENT_AMOUNT] = '100.0000';
        $replacementRows = [$this->replacementRow(null)];
        $quote = $this->modelWithoutConstructor(Quote::class);
        $quote->setId(41);
        $exchangeResource = $this->createMock(ExchangeResource::class);
        $exchangeResource->method('getDataForUpdate')
            ->willReturn($exchangeRow);
        $exchangeResource->expects(self::never())->method('save');
        $this->setProperty($command, 'exchangeResource', $exchangeResource);
        $this->setProperty(
            $command,
            'exchangeFactory',
            $this->factoryMock(
                ExchangeFactory::class,
                static fn (): Exchange =>
                    (new \ReflectionClass(Exchange::class))
                        ->newInstanceWithoutConstructor()
            )
        );
        $replacementResource = $this->createMock(
            ReplacementItemResource::class
        );
        $replacementResource->method('getRowsByExchangeIdForUpdate')
            ->willReturn($replacementRows);
        $this->setProperty(
            $command,
            'replacementItemResource',
            $replacementResource
        );
        $aggregate = $this->createMock(FinancialAggregateCalculator::class);
        $aggregate->method('getReplacementAmount')
            ->willReturn('100.0000');
        $this->setProperty($command, 'aggregateCalculator', $aggregate);
        $hasher = $this->createMock(IntentHasher::class);
        $hasher->method('execute')->willReturn(self::INTENT_HASH);
        $this->setProperty($command, 'intentHasher', $hasher);
        $this->setProperty($command, 'moneyMath', new DecimalMath());
        $this->setProperty($command, 'versionGuard', new VersionGuard());
        $documentResource = $this->createMock(DocumentLinkResource::class);
        $documentResource->method('getByOperationKeyForUpdate')
            ->willReturn(null);
        $this->setProperty(
            $command,
            'documentLinkResource',
            $documentResource
        );
        $lookup = $this->createMock(PreparedQuoteLookup::class);
        $lookup->method('find')->willReturn($quote);
        $this->setProperty($command, 'preparedQuoteLookup', $lookup);
        $resolver = $this->createMock(NativeOrderResolver::class);
        $resolver->method('find')->willReturn(null);
        $this->setProperty($command, 'nativeOrderResolver', $resolver);
        $config = $this->createMock(ConfigInterface::class);
        $config->method('isEnabled')->willReturn(true);
        $this->setProperty($command, 'config', $config);
        $original = $this->order(100, '000000100');
        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->method('get')->willReturn($original);
        $this->setProperty($command, 'orderRepository', $orderRepository);
        $quotePreparer = $this->createMock(QuotePreparer::class);
        $quotePreparer->expects(self::once())
            ->method('execute')
            ->willReturn($quote);
        $this->setProperty($command, 'quotePreparer', $quotePreparer);

        $method = new \ReflectionMethod(
            CreateReplacementOrder::class,
            'prepareLocked'
        );
        $result = $method->invoke(
            $command,
            7,
            100,
            1,
            5,
            null,
            'sales-exchange:replacement-order:v1:7'
        );

        self::assertSame(2, $result['version']);
        self::assertSame(41, $result['quote_id']);
        self::assertNull($result['result']);
    }

    public function testStaleTerminalReplayIgnoresMutableStatusAndDisabledConfig(): void
    {
        $command = $this->commandWithoutConstructor();
        $exchangeRow = $this->exchangeRow(
            ReplacementStatus::DELIVERED,
            9
        );
        $exchangeRow[ExchangeInterface::EXCHANGE_STATUS] =
            ExchangeStatus::COMPLETED;
        $exchangeRow[ExchangeInterface::SETTLEMENT_STATUS] =
            SettlementStatus::BALANCED;
        $exchangeRow[ExchangeInterface::REPLACEMENT_AMOUNT] = '100.0000';
        $exchangeRow[ExchangeInterface::NATIVE_REPLACEMENT_AMOUNT] =
            '114.0000';
        $exchangeRow[ExchangeInterface::BASE_NATIVE_REPLACEMENT_AMOUNT] =
            '114.0000';
        $replacementRows = [$this->replacementRow(501)];
        $quote = $this->modelWithoutConstructor(Quote::class);
        $quote->setId(41);
        $native = $this->order(200, '000000200');
        $native->setQuoteId(41)->setStatus('complete');
        $original = $this->order(100, '000000100');
        $snapshot = $this->snapshot();
        $linkRow = $this->linkRow($snapshot);

        $this->wireInitialReadAndMutex($command, $exchangeRow);
        $exchangeResource = $this->createMock(ExchangeResource::class);
        $exchangeResource->method('getDataForUpdate')
            ->willReturn($exchangeRow);
        $this->setProperty($command, 'exchangeResource', $exchangeResource);
        $this->setProperty(
            $command,
            'exchangeFactory',
            $this->factoryMock(
                ExchangeFactory::class,
                static fn (): Exchange =>
                    (new \ReflectionClass(Exchange::class))
                        ->newInstanceWithoutConstructor()
            )
        );
        $replacementResource = $this->createMock(
            ReplacementItemResource::class
        );
        $replacementResource->method('getRowsByExchangeIdForUpdate')
            ->willReturn($replacementRows);
        $this->setProperty(
            $command,
            'replacementItemResource',
            $replacementResource
        );
        $aggregate = $this->createMock(FinancialAggregateCalculator::class);
        $aggregate->method('getReplacementAmount')
            ->willReturn('100.0000');
        $this->setProperty($command, 'aggregateCalculator', $aggregate);
        $hasher = $this->createMock(IntentHasher::class);
        $hasher->method('execute')->willReturn(self::INTENT_HASH);
        $this->setProperty($command, 'intentHasher', $hasher);
        $this->setProperty($command, 'moneyMath', new DecimalMath());

        $documentResource = $this->createMock(DocumentLinkResource::class);
        $documentResource->method('getByOperationKeyForUpdate')
            ->willReturn($linkRow);
        $this->setProperty(
            $command,
            'documentLinkResource',
            $documentResource
        );
        $lookup = $this->createMock(PreparedQuoteLookup::class);
        $lookup->method('find')->willReturn($quote);
        $this->setProperty($command, 'preparedQuoteLookup', $lookup);
        $resolver = $this->createMock(NativeOrderResolver::class);
        $resolver->method('find')->willReturn($native);
        $this->setProperty($command, 'nativeOrderResolver', $resolver);
        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->method('get')->willReturn($original);
        $this->setProperty($command, 'orderRepository', $orderRepository);
        $validator = $this->createMock(NativeOrderValidator::class);
        $validator->method('snapshot')->willReturnCallback(
            function () use ($snapshot): array {
                $this->assertReplacementOrderLocked();

                return $snapshot;
            }
        );
        $this->setProperty($command, 'nativeOrderValidator', $validator);
        $this->setProperty(
            $command,
            'documentLinkFactory',
            $this->factoryMock(
                DocumentLinkFactory::class,
                static fn (): DocumentLink =>
                    (new \ReflectionClass(DocumentLink::class))
                        ->newInstanceWithoutConstructor()
            )
        );
        $config = $this->createMock(ConfigInterface::class);
        $config->expects(self::never())->method('isEnabled');
        $this->setProperty($command, 'config', $config);
        $placer = $this->createMock(NativeOrderPlacer::class);
        $placer->expects(self::never())->method('execute');
        $this->setProperty($command, 'nativeOrderPlacer', $placer);

        $link = $command->execute(7, 1, 5);

        self::assertSame(1, $link->getEntityId());
        self::assertSame('pending', $link->getDocumentStatus());
        self::assertSame(9, $exchangeRow[ExchangeInterface::VERSION]);
        self::assertSame([], $this->heldOrderLocks);
    }

    /**
     * @param array<string, mixed> $exchangeRow
     */
    private function wireInitialReadAndMutex(
        CreateReplacementOrder $command,
        array $exchangeRow
    ): void {
        $this->heldOrderLocks = [];
        $this->outerOrderLockGeneration = 0;
        $this->placementOrderLockGeneration = null;
        $initial = $this->createMock(ExchangeInterface::class);
        $initial->method('getOriginalOrderId')->willReturn(100);
        $repository = $this->createMock(ExchangeRepositoryInterface::class);
        $repository->method('getById')->willReturn($initial);
        $this->setProperty($command, 'exchangeRepository', $repository);
        $mutex = $this->createMock(OrderMutexInterface::class);
        $mutex->method('execute')->willReturnCallback(
            function (
                int $orderId,
                callable $callable,
                array $args
            ) {
                if ($this->heldOrderLocks === []) {
                    self::assertSame(100, $orderId);
                    ++$this->outerOrderLockGeneration;
                } else {
                    self::assertSame([100], $this->heldOrderLocks);
                    self::assertSame(200, $orderId);
                }
                $this->heldOrderLocks[] = $orderId;
                try {
                    return $callable(...$args);
                } finally {
                    array_pop($this->heldOrderLocks);
                }
            }
        );
        $this->setProperty($command, 'orderMutex', $mutex);
    }

    private function assertReplacementOrderLocked(): void
    {
        self::assertSame([100, 200], $this->heldOrderLocks);
    }

    /**
     * @return array<string, mixed>
     */
    private function exchangeRow(string $replacementStatus, int $version): array
    {
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
            ExchangeInterface::REPLACEMENT_STATUS => $replacementStatus,
            ExchangeInterface::SETTLEMENT_STATUS => SettlementStatus::PENDING,
            ExchangeInterface::RETURN_CREDIT_AMOUNT => '80.0000',
            ExchangeInterface::NATIVE_RETURN_CREDIT_AMOUNT => '80.0000',
            ExchangeInterface::BASE_NATIVE_RETURN_CREDIT_AMOUNT => '80.0000',
            ExchangeInterface::NATIVE_REPLACEMENT_AMOUNT => '0.0000',
            ExchangeInterface::BASE_NATIVE_REPLACEMENT_AMOUNT => '0.0000',
            ExchangeInterface::REPLACEMENT_AMOUNT => '0.0000',
            ExchangeInterface::SHIPPING_AMOUNT => '0.0000',
            ExchangeInterface::FEE_AMOUNT => '0.0000',
            ExchangeInterface::BALANCE_AMOUNT => '-80.0000',
            ExchangeInterface::VERSION => $version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function replacementRow(?int $orderItemId): array
    {
        return [
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
        ];
    }

    /**
     * @return array{
     *     amount: string,
     *     base_amount: string,
     *     expected_amount: string,
     *     item_quantities_json: string,
     *     snapshot_hash: string,
     *     item_ids: array<int, int>
     * }
     */
    private function snapshot(): array
    {
        return [
            'amount' => '114.0000',
            'base_amount' => '114.0000',
            'expected_amount' => '100.0000',
            'item_quantities_json' => '{"501":"1.0000"}',
            'snapshot_hash' => str_repeat('b', 64),
            'item_ids' => [71 => 501],
        ];
    }

    /**
     * @param array{
     *     amount: string,
     *     base_amount: string,
     *     expected_amount: string,
     *     item_quantities_json: string,
     *     snapshot_hash: string,
     *     item_ids: array<int, int>
     * } $snapshot
     * @return array<string, mixed>
     */
    private function linkRow(array $snapshot): array
    {
        return [
            DocumentLinkInterface::ENTITY_ID => 1,
            DocumentLinkInterface::EXCHANGE_ID => 7,
            DocumentLinkInterface::DOCUMENT_TYPE => DocumentType::ORDER,
            DocumentLinkInterface::DOCUMENT_ID => 200,
            DocumentLinkInterface::INCREMENT_ID => '000000200',
            DocumentLinkInterface::OPERATION_KEY =>
                'sales-exchange:replacement-order:v1:7',
            DocumentLinkInterface::ITEM_QUANTITIES_JSON =>
                $snapshot['item_quantities_json'],
            DocumentLinkInterface::SNAPSHOT_HASH =>
                $snapshot['snapshot_hash'],
            DocumentLinkInterface::AMOUNT => $snapshot['amount'],
            DocumentLinkInterface::EXPECTED_AMOUNT =>
                $snapshot['expected_amount'],
            DocumentLinkInterface::BASE_AMOUNT => $snapshot['base_amount'],
            DocumentLinkInterface::CURRENCY_CODE => 'EGP',
            DocumentLinkInterface::BASE_CURRENCY_CODE => 'EGP',
            // Deliberately differs from the order's later "complete" status.
            DocumentLinkInterface::DOCUMENT_STATUS => 'pending',
        ];
    }

    private function order(int $entityId, string $incrementId): Order
    {
        $order = $this->modelWithoutConstructor(Order::class);
        $order->setEntityId($entityId)
            ->setIncrementId($incrementId)
            ->setOrderCurrencyCode('EGP')
            ->setBaseCurrencyCode('EGP');

        return $order;
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

    private function commandWithoutConstructor(): CreateReplacementOrder
    {
        return (new \ReflectionClass(CreateReplacementOrder::class))
            ->newInstanceWithoutConstructor();
    }

    private function setProperty(
        CreateReplacementOrder $command,
        string $name,
        object $value
    ): void {
        (new \ReflectionProperty(CreateReplacementOrder::class, $name))
            ->setValue($command, $value);
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
