<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Unit\Model\Settlement;

use Bonlineco\SalesExchange\Api\Data\DocumentLinkInterface;
use Bonlineco\SalesExchange\Api\Data\ExchangeInterface;
use Bonlineco\SalesExchange\Api\Data\SettlementInterface;
use Bonlineco\SalesExchange\Api\DocumentLinkRepositoryInterface;
use Bonlineco\SalesExchange\Api\DocumentType;
use Bonlineco\SalesExchange\Api\ExchangeRepositoryInterface;
use Bonlineco\SalesExchange\Api\Status\ExchangeStatus;
use Bonlineco\SalesExchange\Api\Status\ReplacementStatus;
use Bonlineco\SalesExchange\Api\Status\ReturnStatus;
use Bonlineco\SalesExchange\Api\Status\SettlementStatus;
use Bonlineco\SalesExchange\Model\DocumentLink;
use Bonlineco\SalesExchange\Model\DocumentLinkFactory;
use Bonlineco\SalesExchange\Model\DocumentLinkWriter;
use Bonlineco\SalesExchange\Model\Exchange;
use Bonlineco\SalesExchange\Model\ExchangeFactory;
use Bonlineco\SalesExchange\Model\History;
use Bonlineco\SalesExchange\Model\HistoryFactory;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Bonlineco\SalesExchange\Model\ReplacementOrder\Marker;
use Bonlineco\SalesExchange\Model\ReplacementOrder\NativeOrderValidator;
use Bonlineco\SalesExchange\Model\ResourceModel\DocumentLink as DocumentLinkResource;
use Bonlineco\SalesExchange\Model\ResourceModel\Exchange as ExchangeResource;
use Bonlineco\SalesExchange\Model\ResourceModel\History as HistoryResource;
use Bonlineco\SalesExchange\Model\ResourceModel\ReplacementItem as ReplacementItemResource;
use Bonlineco\SalesExchange\Model\ResourceModel\ReturnItem as ReturnItemResource;
use Bonlineco\SalesExchange\Model\ResourceModel\Settlement as SettlementResource;
use Bonlineco\SalesExchange\Model\Settlement\EligibilityValidator;
use Bonlineco\SalesExchange\Model\Settlement\InvoiceLookup;
use Bonlineco\SalesExchange\Model\Settlement\InvoiceRequestBuilder;
use Bonlineco\SalesExchange\Model\Settlement\LedgerWriter;
use Bonlineco\SalesExchange\Model\Settlement\NativeInvoiceValidator;
use Bonlineco\SalesExchange\Model\Settlement\NativeReturnCreditValidator;
use Bonlineco\SalesExchange\Model\Settlement\OperationKeys;
use Bonlineco\SalesExchange\Model\Settlement\Planner;
use Bonlineco\SalesExchange\Model\Settlement\ReconcileSettlement;
use Bonlineco\SalesExchange\Model\StateTransitionGuard;
use Bonlineco\SalesExchange\Model\VersionGuard;
use Magento\Framework\Event\ManagerInterface;
use Magento\Sales\Api\Data\InvoiceCommentCreationInterface;
use Magento\Sales\Api\Data\InvoiceInterface;
use Magento\Sales\Api\Data\InvoiceItemCreationInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\InvoiceOrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderMutexInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ReconcileSettlementOrchestrationTest extends TestCase
{
    public function testExactTerminalReplaySkipsVersionConfigAndInvoiceWrites(): void
    {
        $row = $this->exchangeRow(
            ReplacementStatus::CANCELLED,
            SettlementStatus::REFUND_ISSUED,
            ExchangeStatus::COMPLETED,
            '0.0000',
            '0.0000',
            '-100.0000',
            99
        );
        $initial = $this->exchangeModel($row);
        $exchangeRepository = $this->createMock(ExchangeRepositoryInterface::class);
        $exchangeRepository->method('getById')->with(7)->willReturn($initial);
        $exchangeResource = $this->createMock(ExchangeResource::class);
        $exchangeResource->method('getDataForUpdate')->with(7)->willReturn($row);
        $exchangeResource->expects(self::never())->method('save');
        $exchangeFactory = $this->createMock(ExchangeFactory::class);
        $exchangeFactory->method('create')->willReturnCallback(
            fn (): Exchange => $this->exchangeModel([])
        );
        $returnRows = [['credited_qty' => '1.0000']];
        $settlementRows = [['canonical' => true]];
        $returnResource = $this->createMock(ReturnItemResource::class);
        $returnResource->method('getRowsByExchangeIdForUpdate')
            ->willReturn($returnRows);
        $replacementResource = $this->createMock(ReplacementItemResource::class);
        $replacementResource->method('getRowsByExchangeIdForUpdate')->willReturn([]);
        $documentResource = $this->createMock(DocumentLinkResource::class);
        $documentResource->method('getRowsByExchangeIdForUpdate')->willReturn([]);
        $settlementResource = $this->createMock(SettlementResource::class);
        $settlementResource->method('getRowsByExchangeIdForUpdate')
            ->willReturn($settlementRows);
        $originalOrder = $this->createMock(OrderInterface::class);
        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->method('get')->with(10)->willReturn($originalOrder);

        $eligibility = $this->createMock(EligibilityValidator::class);
        $eligibility->expects(self::once())->method('assertTerminal');
        $eligibility->expects(self::never())->method('execute');
        $returnValidator = $this->createMock(NativeReturnCreditValidator::class);
        $returnValidator->expects(self::once())->method('execute');
        $entry = $this->createMock(SettlementInterface::class);
        $ledger = $this->createMock(LedgerWriter::class);
        $ledger->expects(self::once())->method('assertExact')
            ->with(self::isInstanceOf(\Bonlineco\SalesExchange\Model\Settlement\Plan::class), $settlementRows)
            ->willReturn([$entry]);
        $ledger->expects(self::never())->method('appendPlan');
        $invoiceOrder = $this->createMock(InvoiceOrderInterface::class);
        $invoiceOrder->expects(self::never())->method('execute');
        $eventManager = $this->createMock(ManagerInterface::class);
        $eventManager->expects(self::never())->method('dispatch');

        $locks = [];
        $orderMutex = $this->callbackMutex($locks);
        $command = $this->command([
            'exchangeRepository' => $exchangeRepository,
            'exchangeResource' => $exchangeResource,
            'exchangeFactory' => $exchangeFactory,
            'returnItemResource' => $returnResource,
            'replacementItemResource' => $replacementResource,
            'documentLinkResource' => $documentResource,
            'settlementResource' => $settlementResource,
            'orderRepository' => $orderRepository,
            'orderMutex' => $orderMutex,
            'planner' => new Planner(new DecimalMath(), new OperationKeys()),
            'eligibilityValidator' => $eligibility,
            'returnCreditValidator' => $returnValidator,
            'ledgerWriter' => $ledger,
            'operationKeys' => new OperationKeys(),
            'invoiceOrder' => $invoiceOrder,
            'eventManager' => $eventManager,
        ]);

        $result = $command->execute(7, 1, 5, 'refund-123');

        self::assertSame(SettlementStatus::REFUND_ISSUED, $result->getSettlementStatus());
        self::assertSame(99, $result->getVersion());
        self::assertSame([10], $locks);
    }

    public function testFreshInvoiceAndLedgerCommitUnderSortedOrderLocks(): void
    {
        $row = $this->exchangeRow(
            ReplacementStatus::ORDERED,
            SettlementStatus::PENDING,
            ExchangeStatus::IN_PROGRESS,
            '120.0000',
            '120.0000',
            '20.0000',
            5
        );
        $initial = $this->exchangeModel($row);
        $exchangeRepository = $this->createMock(ExchangeRepositoryInterface::class);
        $exchangeRepository->method('getById')->with(7)->willReturn($initial);

        $orderLinkRow = $this->orderLinkRow();
        $orderLink = $this->documentLinkModel($orderLinkRow);
        $documentRepository = $this->createMock(DocumentLinkRepositoryInterface::class);
        $documentRepository->method('getByOperationKey')
            ->with('sales-exchange:replacement-order:v1:7')
            ->willReturn($orderLink);

        $exchangeResource = $this->createMock(ExchangeResource::class);
        $exchangeResource->method('getDataForUpdate')->with(7)->willReturn($row);
        $exchangeResource->expects(self::once())->method('save')
            ->with(self::callback(
                static fn (ExchangeInterface $exchange): bool =>
                    $exchange->getSettlementStatus()
                        === SettlementStatus::PAYMENT_RECEIVED
                    && $exchange->getExchangeStatus() === ExchangeStatus::IN_PROGRESS
                    && $exchange->getVersion() === 6
            ));
        $exchangeFactory = $this->createMock(ExchangeFactory::class);
        $exchangeFactory->method('create')->willReturnCallback(
            fn (): Exchange => $this->exchangeModel([])
        );
        $returnRows = [['credited_qty' => '1.0000']];
        $replacementRows = [['entity_id' => 1]];
        $returnResource = $this->createMock(ReturnItemResource::class);
        $returnResource->method('getRowsByExchangeIdForUpdate')
            ->willReturn($returnRows);
        $replacementResource = $this->createMock(ReplacementItemResource::class);
        $replacementResource->method('getRowsByExchangeIdForUpdate')
            ->willReturn($replacementRows);
        $documentResource = $this->createMock(DocumentLinkResource::class);
        $documentResource->method('getRowsByExchangeIdForUpdate')
            ->willReturn([$orderLinkRow]);
        $ledgerRows = [['canonical' => true]];
        $settlementResource = $this->createMock(SettlementResource::class);
        $settlementResource->method('getRowsByExchangeIdForUpdate')
            ->willReturnOnConsecutiveCalls([], [], [], $ledgerRows);

        $originalOrder = $this->createMock(OrderInterface::class);
        $replacementOrder = $this->replacementOrder();
        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->method('get')->willReturnCallback(
            static fn (int $orderId): OrderInterface =>
                $orderId === 10 ? $originalOrder : $replacementOrder
        );
        $orderSnapshot = [
            'amount' => '120.0000',
            'base_amount' => '120.0000',
            'expected_amount' => '120.0000',
            'item_quantities_json' => '{"11":"1.0000"}',
            'snapshot_hash' => str_repeat('b', 64),
            'item_ids' => [1 => 11],
        ];
        $nativeOrderValidator = $this->createMock(NativeOrderValidator::class);
        $nativeOrderValidator->method('snapshot')->willReturn($orderSnapshot);

        $invoice = $this->createMock(InvoiceInterface::class);
        $invoice->method('getEntityId')->willReturn(88);
        $invoice->method('getIncrementId')->willReturn('INV000088');
        $invoice->method('getState')->willReturn(2);
        $invoiceLookup = $this->createMock(InvoiceLookup::class);
        $invoiceLookup->method('find')
            ->willReturnOnConsecutiveCalls(null, null, $invoice);
        $invoiceValidator = $this->createMock(NativeInvoiceValidator::class);
        $invoiceValidator->method('hasOperationMarker')->willReturn(true);
        $invoiceSnapshot = [
            'amount' => '120.0000',
            'base_amount' => '120.0000',
            'item_quantities_json' => '{"11":"1.0000"}',
            'snapshot_hash' => str_repeat('c', 64),
        ];
        $invoiceValidator->method('snapshot')->willReturn($invoiceSnapshot);

        $invoiceItem = $this->createMock(InvoiceItemCreationInterface::class);
        $nativeComment = $this->createMock(InvoiceCommentCreationInterface::class);
        $requestBuilder = $this->createMock(InvoiceRequestBuilder::class);
        $requestBuilder->expects(self::once())->method('buildItems')
            ->with($replacementOrder)->willReturn([$invoiceItem]);
        $requestBuilder->expects(self::once())->method('buildComment')
            ->willReturn($nativeComment);
        $requestBuilder->method('quantities')->willReturn([11 => '1.0000']);

        $invoiceOrder = $this->createMock(InvoiceOrderInterface::class);
        $invoiceOrder->expects(self::once())->method('execute')
            ->with(55, false, [$invoiceItem], false, false, $nativeComment)
            ->willReturn(88);
        $eligibility = $this->createMock(EligibilityValidator::class);
        $eligibility->expects(self::exactly(3))->method('execute');
        $returnValidator = $this->createMock(NativeReturnCreditValidator::class);
        $returnValidator->expects(self::exactly(3))->method('execute');

        $documentFactory = $this->createMock(DocumentLinkFactory::class);
        $documentFactory->method('create')->willReturnCallback(
            fn (): DocumentLink => $this->documentLinkModel([])
        );
        $documentWriter = $this->createMock(DocumentLinkWriter::class);
        $documentWriter->expects(self::once())->method('append')
            ->with(self::callback(
                static fn (DocumentLinkInterface $link): bool =>
                    $link->getOperationKey()
                        === 'sales-exchange:settlement-invoice:v1:7'
                    && $link->getDocumentId() === 88
            ))
            ->willReturnCallback(
                static fn (DocumentLinkInterface $link): DocumentLinkInterface => $link
            );
        $entry = $this->createMock(SettlementInterface::class);
        $ledger = $this->createMock(LedgerWriter::class);
        $ledger->expects(self::once())->method('appendPlan')->willReturn([$entry]);

        $historyFactory = $this->createMock(HistoryFactory::class);
        $historyFactory->method('create')->willReturnCallback(
            fn (): History => $this->historyModel()
        );
        $historyResource = $this->createMock(HistoryResource::class);
        $historyResource->expects(self::once())->method('save');
        $eventManager = $this->createMock(ManagerInterface::class);
        $eventManager->expects(self::once())->method('dispatch')
            ->with(
                'bonlineco_sales_exchange_settlement_reconciled',
                    self::callback(
                        static fn (mixed $value): bool => is_array($value)
                    )
            );
        $logger = $this->createMock(LoggerInterface::class);
        $locks = [];

        $command = $this->command([
            'exchangeRepository' => $exchangeRepository,
            'documentLinkRepository' => $documentRepository,
            'exchangeResource' => $exchangeResource,
            'exchangeFactory' => $exchangeFactory,
            'returnItemResource' => $returnResource,
            'replacementItemResource' => $replacementResource,
            'documentLinkResource' => $documentResource,
            'settlementResource' => $settlementResource,
            'documentLinkFactory' => $documentFactory,
            'documentLinkWriter' => $documentWriter,
            'historyFactory' => $historyFactory,
            'historyResource' => $historyResource,
            'orderMutex' => $this->callbackMutex($locks),
            'orderRepository' => $orderRepository,
            'invoiceOrder' => $invoiceOrder,
            'planner' => new Planner(new DecimalMath(), new OperationKeys()),
            'eligibilityValidator' => $eligibility,
            'returnCreditValidator' => $returnValidator,
            'nativeOrderValidator' => $nativeOrderValidator,
            'invoiceRequestBuilder' => $requestBuilder,
            'invoiceValidator' => $invoiceValidator,
            'invoiceLookup' => $invoiceLookup,
            'ledgerWriter' => $ledger,
            'operationKeys' => new OperationKeys(),
            'versionGuard' => new VersionGuard(),
            'transitionGuard' => new StateTransitionGuard(),
            'eventManager' => $eventManager,
            'logger' => $logger,
        ]);

        $result = $command->execute(7, 5, 9, 'PAY-123', 'settled');

        self::assertSame(SettlementStatus::PAYMENT_RECEIVED, $result->getSettlementStatus());
        self::assertSame(ExchangeStatus::IN_PROGRESS, $result->getExchangeStatus());
        self::assertSame([10, 55, 10, 55], $locks);
    }

    /**
     * @param array<string, object> $properties
     */
    private function command(array $properties): ReconcileSettlement
    {
        $reflection = new \ReflectionClass(ReconcileSettlement::class);
        /** @var ReconcileSettlement $command */
        $command = $reflection->newInstanceWithoutConstructor();
        foreach ($properties as $name => $value) {
            $reflection->getProperty($name)->setValue($command, $value);
        }

        return $command;
    }

    /**
     * @param int[] $locks
     */
    private function callbackMutex(array &$locks): OrderMutexInterface
    {
        $mutex = $this->createMock(OrderMutexInterface::class);
        $mutex->method('execute')->willReturnCallback(
            static function (
                int $orderId,
                callable $operation,
                array $arguments = []
            ) use (&$locks) {
                $locks[] = $orderId;

                return $operation(...$arguments);
            }
        );

        return $mutex;
    }

    /**
     * @return array<string, mixed>
     */
    private function exchangeRow(
        string $replacementStatus,
        string $settlementStatus,
        string $exchangeStatus,
        string $nativeReplacement,
        string $baseNativeReplacement,
        string $balance,
        int $version
    ): array {
        return [
            ExchangeInterface::ENTITY_ID => 7,
            ExchangeInterface::INCREMENT_ID => 'EX000007',
            ExchangeInterface::ORIGINAL_ORDER_ID => 10,
            ExchangeInterface::STORE_ID => 1,
            ExchangeInterface::CURRENCY_CODE => 'EGP',
            ExchangeInterface::BASE_CURRENCY_CODE => 'EGP',
            ExchangeInterface::EXCHANGE_STATUS => $exchangeStatus,
            ExchangeInterface::RETURN_STATUS => ReturnStatus::ACCEPTED,
            ExchangeInterface::REPLACEMENT_STATUS => $replacementStatus,
            ExchangeInterface::SETTLEMENT_STATUS => $settlementStatus,
            ExchangeInterface::NATIVE_RETURN_CREDIT_AMOUNT => '100.0000',
            ExchangeInterface::BASE_NATIVE_RETURN_CREDIT_AMOUNT => '100.0000',
            ExchangeInterface::NATIVE_REPLACEMENT_AMOUNT => $nativeReplacement,
            ExchangeInterface::BASE_NATIVE_REPLACEMENT_AMOUNT
                => $baseNativeReplacement,
            ExchangeInterface::REPLACEMENT_AMOUNT => $nativeReplacement,
            ExchangeInterface::SHIPPING_AMOUNT => '0.0000',
            ExchangeInterface::FEE_AMOUNT => '0.0000',
            ExchangeInterface::BALANCE_AMOUNT => $balance,
            ExchangeInterface::VERSION => $version,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function exchangeModel(array $data): Exchange
    {
        /** @var Exchange $exchange */
        $exchange = (new \ReflectionClass(Exchange::class))
            ->newInstanceWithoutConstructor();
        $exchange->setData($data);

        return $exchange;
    }

    /**
     * @return array<string, mixed>
     */
    private function orderLinkRow(): array
    {
        return [
            DocumentLinkInterface::EXCHANGE_ID => 7,
            DocumentLinkInterface::DOCUMENT_TYPE => DocumentType::ORDER,
            DocumentLinkInterface::DOCUMENT_ID => 55,
            DocumentLinkInterface::INCREMENT_ID => '000000055',
            DocumentLinkInterface::OPERATION_KEY
                => 'sales-exchange:replacement-order:v1:7',
            DocumentLinkInterface::ITEM_QUANTITIES_JSON => '{"11":"1.0000"}',
            DocumentLinkInterface::SNAPSHOT_HASH => str_repeat('b', 64),
            DocumentLinkInterface::AMOUNT => '120.0000',
            DocumentLinkInterface::EXPECTED_AMOUNT => '120.0000',
            DocumentLinkInterface::BASE_AMOUNT => '120.0000',
            DocumentLinkInterface::CURRENCY_CODE => 'EGP',
            DocumentLinkInterface::BASE_CURRENCY_CODE => 'EGP',
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function documentLinkModel(array $data): DocumentLink
    {
        /** @var DocumentLink $link */
        $link = (new \ReflectionClass(DocumentLink::class))
            ->newInstanceWithoutConstructor();
        $link->setData($data);

        return $link;
    }

    private function replacementOrder(): Order
    {
        /** @var Order $order */
        $order = (new \ReflectionClass(Order::class))->newInstanceWithoutConstructor();
        $order->setData([
            'entity_id' => 55,
            'increment_id' => '000000055',
            'order_currency_code' => 'EGP',
            'base_currency_code' => 'EGP',
            Marker::INTENT_HASH => str_repeat('a', 64),
        ]);

        return $order;
    }

    private function historyModel(): History
    {
        /** @var History $history */
        $history = (new \ReflectionClass(History::class))
            ->newInstanceWithoutConstructor();

        return $history;
    }
}
