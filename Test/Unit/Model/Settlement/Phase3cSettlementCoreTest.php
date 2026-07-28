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
use Bonlineco\SalesExchange\Api\DocumentType;
use Bonlineco\SalesExchange\Api\Settlement\EntryStatus;
use Bonlineco\SalesExchange\Api\Settlement\Type;
use Bonlineco\SalesExchange\Api\Status\ExchangeStatus;
use Bonlineco\SalesExchange\Api\Status\ReplacementStatus;
use Bonlineco\SalesExchange\Api\Status\SettlementStatus;
use Bonlineco\SalesExchange\Api\Status\StateDimension;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Exchange;
use Bonlineco\SalesExchange\Model\ExchangeFactory;
use Bonlineco\SalesExchange\Model\CompletionValidator;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Bonlineco\SalesExchange\Model\ReplacementOrder\Marker;
use Bonlineco\SalesExchange\Model\ReplacementOrder\NativeOrderValidator;
use Bonlineco\SalesExchange\Model\ResourceModel\Exchange as ExchangeResource;
use Bonlineco\SalesExchange\Model\ResourceModel\Settlement as SettlementResource;
use Bonlineco\SalesExchange\Model\Settlement\InvoiceLookup;
use Bonlineco\SalesExchange\Model\Settlement\InvoiceRequestBuilder;
use Bonlineco\SalesExchange\Model\Settlement\NativeInvoiceValidator;
use Bonlineco\SalesExchange\Model\Settlement\OperationKeys;
use Bonlineco\SalesExchange\Model\Settlement\Plan;
use Bonlineco\SalesExchange\Model\Settlement\Planner;
use Bonlineco\SalesExchange\Model\Settlement\ReconcileSettlement;
use Bonlineco\SalesExchange\Model\SettlementIntentValidator;
use Bonlineco\SalesExchange\Model\SettlementRepository;
use Bonlineco\SalesExchange\Model\StateTransitionGuard;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Model\AbstractModel;
use Magento\Sales\Api\Data\InvoiceCommentInterface;
use Magento\Sales\Api\Data\InvoiceInterface;
use Magento\Sales\Api\Data\InvoiceItemCreationInterface;
use Magento\Sales\Api\Data\InvoiceItemCreationInterfaceFactory;
use Magento\Sales\Api\Data\InvoiceItemInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice as SalesInvoice;
use PHPUnit\Framework\TestCase;

class Phase3cSettlementCoreTest extends TestCase
{
    public function testPositiveBalancePlansFullCreditAndCustomerPayment(): void
    {
        $plan = $this->planner()->execute(
            $this->exchange(
                ReplacementStatus::ORDERED,
                '100.0000',
                '120.0000',
                '120.0000',
                '20.0000'
            ),
            ' processor-123 '
        );

        self::assertSame(SettlementStatus::PAYMENT_RECEIVED, $plan->getTargetStatus());
        self::assertSame('20.0000', $plan->getBalanceAmount());
        self::assertSame(
            [
                [
                    'type' => Type::RETURN_CREDIT,
                    'amount' => '100.0000',
                    'idempotency_key'
                        => 'sales-exchange:settlement:return-credit:v1:7',
                    'external_reference' => null,
                ],
                [
                    'type' => Type::CUSTOMER_PAYMENT,
                    'amount' => '20.0000',
                    'idempotency_key'
                        => 'sales-exchange:settlement:customer-payment:v1:7',
                    'external_reference' => 'processor-123',
                ],
            ],
            $plan->getEntries()
        );
    }

    public function testCancelledReplacementPlansRefundOnlySettlement(): void
    {
        $plan = $this->planner()->execute(
            $this->exchange(
                ReplacementStatus::CANCELLED,
                '100.0000',
                '0.0000',
                '0.0000',
                '-100.0000'
            ),
            'refund-123'
        );

        self::assertFalse($plan->requiresInvoice());
        self::assertSame(SettlementStatus::REFUND_ISSUED, $plan->getTargetStatus());
        self::assertSame(
            [Type::RETURN_CREDIT, Type::MERCHANT_REFUND],
            array_column($plan->getEntries(), 'type')
        );
        self::assertSame('-100.0000', $plan->getEntries()[1]['amount']);
    }

    public function testExternalReferenceIsRejectedForBalancedSettlement(): void
    {
        $this->expectException(InvariantViolationException::class);

        $this->planner()->execute(
            $this->exchange(
                ReplacementStatus::ORDERED,
                '100.0000',
                '100.0000',
                '100.0000',
                '0.0000'
            ),
            'unexpected-reference'
        );
    }

    public function testGenericWorkflowCannotBypassCanonicalSettlementCommand(): void
    {
        $guard = new StateTransitionGuard();
        foreach (
            [
                SettlementStatus::PAYMENT_DUE,
                SettlementStatus::REFUND_DUE,
                SettlementStatus::BALANCED,
                SettlementStatus::PAYMENT_RECEIVED,
                SettlementStatus::REFUND_ISSUED,
            ] as $target
        ) {
            try {
                $guard->execute(
                    StateDimension::SETTLEMENT,
                    SettlementStatus::PENDING,
                    $target
                );
                self::fail('Generic settlement advancement must be reserved.');
            } catch (InvariantViolationException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
        $guard->execute(
            StateDimension::SETTLEMENT,
            SettlementStatus::PENDING,
            SettlementStatus::CANCELLED
        );
        $guard->executeSettlementReconciliation(
            SettlementStatus::PENDING,
            SettlementStatus::PAYMENT_RECEIVED
        );
        self::assertTrue(true);
    }

    public function testOrderedSettlementProjectionPreservesInProgressExchange(): void
    {
        $exchange = $this->getMockBuilder(Exchange::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'setData',
                'setSettlementStatus',
                'setBalanceAmount',
                'setVersion',
                'setExchangeStatus',
                'getExchangeStatus',
                'getReplacementStatus',
            ])
            ->getMock();
        $exchange->method('setData')->willReturnSelf();
        $exchange->method('setSettlementStatus')->willReturnSelf();
        $exchange->method('setBalanceAmount')->willReturnSelf();
        $exchange->method('setVersion')->willReturnSelf();
        $exchange->expects(self::never())->method('setExchangeStatus');
        $exchange->method('getExchangeStatus')->willReturn(ExchangeStatus::IN_PROGRESS);
        $exchange->method('getReplacementStatus')
            ->willReturn(ReplacementStatus::ORDERED);

        $factory = $this->createMock(ExchangeFactory::class);
        $factory->method('create')->willReturn($exchange);
        $resource = $this->createMock(ExchangeResource::class);
        $resource->expects(self::once())->method('save')->with($exchange);

        $reflection = new \ReflectionClass(ReconcileSettlement::class);
        $command = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('exchangeFactory')->setValue($command, $factory);
        $reflection->getProperty('exchangeResource')->setValue($command, $resource);
        $reflection->getProperty('transitionGuard')
            ->setValue($command, new StateTransitionGuard());
        $plan = new Plan(
            7,
            false,
            '100.0000',
            '120.0000',
            '120.0000',
            '20.0000',
            'EGP',
            'EGP',
            SettlementStatus::PAYMENT_RECEIVED,
            []
        );
        $saved = $reflection->getMethod('persistExchange')->invoke(
            $command,
            [
                ExchangeInterface::EXCHANGE_STATUS => ExchangeStatus::IN_PROGRESS,
                ExchangeInterface::SETTLEMENT_STATUS => SettlementStatus::PENDING,
            ],
            $plan,
            9,
            [],
            [],
            []
        );

        self::assertSame($exchange, $saved);
        self::assertSame(ExchangeStatus::IN_PROGRESS, $saved->getExchangeStatus());
    }

    public function testCancelledRefundOnlySettlementCompletesExchange(): void
    {
        $status = ExchangeStatus::IN_PROGRESS;
        $exchange = $this->getMockBuilder(Exchange::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'setData',
                'setSettlementStatus',
                'setBalanceAmount',
                'setVersion',
                'getReplacementStatus',
                'getExchangeStatus',
                'setExchangeStatus',
            ])
            ->getMock();
        $exchange->method('setData')->willReturnSelf();
        $exchange->method('setSettlementStatus')->willReturnSelf();
        $exchange->method('setBalanceAmount')->willReturnSelf();
        $exchange->method('setVersion')->willReturnSelf();
        $exchange->method('getReplacementStatus')
            ->willReturn(ReplacementStatus::CANCELLED);
        $exchange->method('getExchangeStatus')
            ->willReturnCallback(static function () use (&$status): string {
                return $status;
            });
        $exchange->expects(self::once())->method('setExchangeStatus')
            ->with(ExchangeStatus::COMPLETED)
            ->willReturnCallback(
                static function () use (&$status, $exchange): Exchange {
                    $status = ExchangeStatus::COMPLETED;

                    return $exchange;
                }
            );
        $completionValidator = $this->createMock(CompletionValidator::class);
        $completionValidator->expects(self::once())->method('execute')
            ->with($exchange, [['return' => true]], [['replacement' => true]], [['ledger' => true]]);
        $factory = $this->createMock(ExchangeFactory::class);
        $factory->method('create')->willReturn($exchange);
        $resource = $this->createMock(ExchangeResource::class);
        $resource->expects(self::once())->method('save')->with($exchange);

        $reflection = new \ReflectionClass(ReconcileSettlement::class);
        $command = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('exchangeFactory')->setValue($command, $factory);
        $reflection->getProperty('exchangeResource')->setValue($command, $resource);
        $reflection->getProperty('transitionGuard')
            ->setValue($command, new StateTransitionGuard());
        $reflection->getProperty('completionValidator')
            ->setValue($command, $completionValidator);
        $saved = $reflection->getMethod('persistExchange')->invoke(
            $command,
            [
                ExchangeInterface::EXCHANGE_STATUS => ExchangeStatus::IN_PROGRESS,
                ExchangeInterface::SETTLEMENT_STATUS => SettlementStatus::PENDING,
            ],
            new Plan(
                7,
                true,
                '100.0000',
                '0.0000',
                '0.0000',
                '-100.0000',
                'EGP',
                'EGP',
                SettlementStatus::REFUND_ISSUED,
                []
            ),
            9,
            [['return' => true]],
            [['replacement' => true]],
            [['ledger' => true]]
        );

        self::assertSame(ExchangeStatus::COMPLETED, $saved->getExchangeStatus());
    }

    public function testExistingUnlinkedInvoiceFailsClosedEvenWithCommentMarker(): void
    {
        $exchange = $this->exchange(
            ReplacementStatus::ORDERED,
            '100.0000',
            '120.0000',
            '120.0000',
            '20.0000'
        );
        $original = $this->createMock(OrderInterface::class);
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getData',
                'getEntityId',
                'getIncrementId',
                'getOrderCurrencyCode',
                'getBaseCurrencyCode',
            ])
            ->getMock();
        $intentHash = str_repeat('a', 64);
        $order->method('getData')->willReturn($intentHash);
        $order->method('getEntityId')->willReturn(55);
        $order->method('getIncrementId')->willReturn('000000055');
        $order->method('getOrderCurrencyCode')->willReturn('EGP');
        $order->method('getBaseCurrencyCode')->willReturn('EGP');
        $snapshot = [
            'amount' => '120.0000',
            'base_amount' => '120.0000',
            'expected_amount' => '120.0000',
            'item_quantities_json' => '{"11":"1.0000"}',
            'snapshot_hash' => str_repeat('b', 64),
            'item_ids' => [1 => 11],
        ];
        $orderLink = [
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

        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->method('get')->with(55)->willReturn($order);
        $nativeOrderValidator = $this->createMock(NativeOrderValidator::class);
        $nativeOrderValidator->method('snapshot')->willReturn($snapshot);
        $invoice = $this->createMock(InvoiceInterface::class);
        $invoiceLookup = $this->createMock(InvoiceLookup::class);
        $invoiceLookup->method('find')->with(55)->willReturn($invoice);
        $invoiceValidator = $this->createMock(NativeInvoiceValidator::class);
        $invoiceValidator->method('hasOperationMarker')->willReturn(true);

        $reflection = new \ReflectionClass(ReconcileSettlement::class);
        $command = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('orderRepository')
            ->setValue($command, $orderRepository);
        $reflection->getProperty('nativeOrderValidator')
            ->setValue($command, $nativeOrderValidator);
        $reflection->getProperty('invoiceLookup')->setValue($command, $invoiceLookup);
        $reflection->getProperty('invoiceValidator')
            ->setValue($command, $invoiceValidator);
        $reflection->getProperty('operationKeys')
            ->setValue($command, new OperationKeys());

        $this->expectException(InvariantViolationException::class);
        $reflection->getMethod('loadCommercialState')->invoke(
            $command,
            [
                'exchange' => $exchange,
                'original_order' => $original,
                'replacement_rows' => [],
                'document_rows' => [$orderLink],
            ],
            new Plan(
                7,
                false,
                '100.0000',
                '120.0000',
                '120.0000',
                '20.0000',
                'EGP',
                'EGP',
                SettlementStatus::PAYMENT_RECEIVED,
                []
            ),
            55
        );
    }

    public function testInvoiceRequestUsesExplicitFullItemDtos(): void
    {
        $orderItem = $this->createMock(OrderItemInterface::class);
        $orderItem->method('getItemId')->willReturn(11);
        $orderItem->method('getQtyOrdered')->willReturn(2);
        $orderItem->method('getQtyInvoiced')->willReturn(0);
        $orderItem->method('getQtyCanceled')->willReturn(0);
        $orderItem->method('getQtyRefunded')->willReturn(0);
        $order = $this->createMock(OrderInterface::class);
        $order->method('getItems')->willReturn([$orderItem]);

        $item = $this->createMock(InvoiceItemCreationInterface::class);
        $item->expects(self::once())->method('setOrderItemId')->with(11)
            ->willReturnSelf();
        $item->expects(self::once())->method('setQty')->with(2.0)
            ->willReturnSelf();
        $factory = $this->createMock(InvoiceItemCreationInterfaceFactory::class);
        $factory->method('create')->willReturn($item);
        $commentFactory = $this->createMock(
            \Magento\Sales\Api\Data\InvoiceCommentCreationInterfaceFactory::class
        );
        $builder = new InvoiceRequestBuilder(
            $factory,
            $commentFactory,
            new DecimalMath(4, 12)
        );

        self::assertSame([$item], $builder->buildItems($order));
    }

    public function testNativeInvoiceValidatorAcceptsOnePaidFullOfflineInvoice(): void
    {
        $operationKey = 'sales-exchange:settlement-invoice:v1:7';
        $comment = $this->createMock(InvoiceCommentInterface::class);
        $comment->method('getComment')->willReturn(
            'Created by exchange EX000007 (' . $operationKey . ').'
        );
        $invoiceItem = $this->createMock(InvoiceItemInterface::class);
        $invoiceItem->method('getEntityId')->willReturn(99);
        $invoiceItem->method('getOrderItemId')->willReturn(11);
        $invoiceItem->method('getQty')->willReturn(2);
        $invoice = $this->createMock(InvoiceInterface::class);
        $invoice->method('getEntityId')->willReturn(88);
        $invoice->method('getIncrementId')->willReturn('INV000088');
        $invoice->method('getOrderId')->willReturn(55);
        $invoice->method('getState')->willReturn(SalesInvoice::STATE_PAID);
        $invoice->method('getOrderCurrencyCode')->willReturn('EGP');
        $invoice->method('getBaseCurrencyCode')->willReturn('EGP');
        $invoice->method('getGrandTotal')->willReturn(120);
        $invoice->method('getBaseGrandTotal')->willReturn(120);
        $invoice->method('getTotalQty')->willReturn(2);
        $invoice->method('getComments')->willReturn([$comment]);
        $invoice->method('getItems')->willReturn([$invoiceItem]);

        $payment = $this->createMock(OrderPaymentInterface::class);
        $payment->method('getLastTransId')->willReturn(null);
        $orderItem = $this->createMock(OrderItemInterface::class);
        $orderItem->method('getItemId')->willReturn(11);
        $orderItem->method('getQtyInvoiced')->willReturn(2);
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(55);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getTotalInvoiced')->willReturn(120);
        $order->method('getBaseTotalInvoiced')->willReturn(120);
        $order->method('getTotalPaid')->willReturn(120);
        $order->method('getBaseTotalPaid')->willReturn(120);
        $order->method('getItems')->willReturn([$orderItem]);

        $snapshot = (new NativeInvoiceValidator(
            new DecimalMath(),
            new DecimalMath(4, 12),
            new Json()
        ))->snapshot(
            $invoice,
            $order,
            new Plan(
                7,
                false,
                '100.0000',
                '120.0000',
                '120.0000',
                '20.0000',
                'EGP',
                'EGP',
                SettlementStatus::PAYMENT_RECEIVED,
                []
            ),
            [11 => '2.0000']
        );

        self::assertSame('120.0000', $snapshot['amount']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $snapshot['snapshot_hash']);
    }

    public function testNativeInvoiceValidatorRejectsTamperedComponentAllocation(): void
    {
        $invoice = $this->createMock(InvoiceInterface::class);
        $invoice->method('getSubtotal')->willReturn(119);
        $order = $this->createMock(OrderInterface::class);
        $order->method('getSubtotal')->willReturn(120);
        $validator = new NativeInvoiceValidator(
            new DecimalMath(),
            new DecimalMath(4, 12),
            new Json()
        );
        $this->expectException(InvariantViolationException::class);

        (new \ReflectionClass($validator))->getMethod('assertDocumentComponents')
            ->invoke($validator, $invoice, $order);
    }

    public function testPublicLedgerTransitionRemainsAvailableOutsideReservedNamespace(): void
    {
        $repository = (new \ReflectionClass(SettlementRepository::class))
            ->newInstanceWithoutConstructor();
        (new \ReflectionClass($repository))->getMethod('assertStatusTransition')
            ->invoke(
                $repository,
                EntryStatus::PENDING,
                EntryStatus::SUCCEEDED
            );

        $resource = (new \ReflectionClass(SettlementResource::class))
            ->newInstanceWithoutConstructor();
        $model = $this->createMock(AbstractModel::class);
        $model->method('getId')->willReturn(12);
        $model->method('getData')->with('idempotency_key')
            ->willReturn('merchant-public-operation-12');
        self::assertSame(
            $resource,
            (new \ReflectionClass($resource))->getMethod('_beforeSave')
                ->invoke($resource, $model)
        );
    }

    public function testPublicRepositoryReservesCanonicalLedgerNamespace(): void
    {
        $settlement = $this->createMock(SettlementInterface::class);
        $settlement->method('getType')->willReturn(Type::RETURN_CREDIT);
        $settlement->method('getStatus')->willReturn(EntryStatus::SUCCEEDED);
        $settlement->method('getCurrencyCode')->willReturn('EGP');
        $settlement->method('getIdempotencyKey')->willReturn(
            'sales-exchange:settlement:return-credit:v1:7'
        );
        $repositoryReflection = new \ReflectionClass(SettlementRepository::class);
        $repository = $repositoryReflection->newInstanceWithoutConstructor();
        $repositoryReflection->getProperty('decimalMath')
            ->setValue($repository, new DecimalMath());
        $this->expectException(InvariantViolationException::class);

        $repositoryReflection->getMethod('validateAndNormalize')
            ->invoke($repository, $settlement);
    }

    public function testIdempotentLedgerCollisionIncludesExternalReference(): void
    {
        $requested = $this->settlement('processor-1');
        $persisted = $this->settlement('processor-2');
        $this->expectException(InvariantViolationException::class);

        (new SettlementIntentValidator(new DecimalMath()))->execute(
            $requested,
            $persisted
        );
    }

    private function planner(): Planner
    {
        return new Planner(new DecimalMath(), new OperationKeys());
    }

    private function exchange(
        string $replacementStatus,
        string $returnCredit,
        string $replacementAmount,
        string $baseReplacementAmount,
        string $balance
    ): ExchangeInterface {
        $exchange = $this->createMock(ExchangeInterface::class);
        $exchange->method('getEntityId')->willReturn(7);
        $exchange->method('getExchangeStatus')->willReturn(ExchangeStatus::IN_PROGRESS);
        $exchange->method('getReplacementStatus')->willReturn($replacementStatus);
        $exchange->method('getNativeReturnCreditAmount')->willReturn($returnCredit);
        $exchange->method('getNativeReplacementAmount')->willReturn($replacementAmount);
        $exchange->method('getBaseNativeReplacementAmount')
            ->willReturn($baseReplacementAmount);
        $exchange->method('getBalanceAmount')->willReturn($balance);
        $exchange->method('getCurrencyCode')->willReturn('EGP');
        $exchange->method('getBaseCurrencyCode')->willReturn('EGP');

        return $exchange;
    }

    private function settlement(?string $externalReference): SettlementInterface
    {
        $settlement = $this->createMock(SettlementInterface::class);
        $settlement->method('getExchangeId')->willReturn(7);
        $settlement->method('getType')->willReturn(Type::CUSTOMER_PAYMENT);
        $settlement->method('getStatus')->willReturn(EntryStatus::SUCCEEDED);
        $settlement->method('getAmount')->willReturn('20.0000');
        $settlement->method('getCurrencyCode')->willReturn('EGP');
        $settlement->method('getIdempotencyKey')->willReturn(
            'sales-exchange:settlement:customer-payment:v1:7'
        );
        $settlement->method('getExternalReference')->willReturn($externalReference);

        return $settlement;
    }
}
