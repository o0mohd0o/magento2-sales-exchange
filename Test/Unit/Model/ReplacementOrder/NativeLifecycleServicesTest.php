<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Unit\Model\ReplacementOrder;

use Bonlineco\SalesExchange\Api\BalanceCalculatorInterface;
use Bonlineco\SalesExchange\Api\Data\DocumentLinkInterface;
use Bonlineco\SalesExchange\Api\Data\ExchangeInterface;
use Bonlineco\SalesExchange\Api\Data\HistoryInterface;
use Bonlineco\SalesExchange\Api\Data\ReplacementItemInterface;
use Bonlineco\SalesExchange\Api\History\ActorType;
use Bonlineco\SalesExchange\Api\DocumentType;
use Bonlineco\SalesExchange\Api\StateTransitionGuardInterface;
use Bonlineco\SalesExchange\Api\Status\ExchangeStatus;
use Bonlineco\SalesExchange\Api\Status\ReplacementStatus;
use Bonlineco\SalesExchange\Api\Status\ReturnStatus;
use Bonlineco\SalesExchange\Api\Status\SettlementStatus;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\CompletionValidator;
use Bonlineco\SalesExchange\Model\Creditmemo\ReturnCreditProjection;
use Bonlineco\SalesExchange\Model\Exchange;
use Bonlineco\SalesExchange\Model\History;
use Bonlineco\SalesExchange\Model\HistoryFactory;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Bonlineco\SalesExchange\Model\NativeRefund\ReplacementOrderGuard;
use Bonlineco\SalesExchange\Model\Order\FreshOrderLoader;
use Bonlineco\SalesExchange\Model\ReplacementItem;
use Bonlineco\SalesExchange\Model\ReplacementItemFactory;
use Bonlineco\SalesExchange\Model\ReplacementOrder\Marker;
use Bonlineco\SalesExchange\Model\ReplacementOrder\MarkerReader;
use Bonlineco\SalesExchange\Model\ReplacementOrder\NativeCancellationValidator;
use Bonlineco\SalesExchange\Model\ReplacementOrder\NativeCancellationWriter;
use Bonlineco\SalesExchange\Model\ReplacementOrder\NativeDeliveryWriter;
use Bonlineco\SalesExchange\Model\ReplacementOrder\NativeOrderLinkValidator;
use Bonlineco\SalesExchange\Model\ReplacementOrder\NativeOrderValidator;
use Bonlineco\SalesExchange\Model\ReplacementOrder\NativeShipmentValidator;
use Bonlineco\SalesExchange\Model\ReplacementOrder\UnavailableDeliveryProofProvider;
use Bonlineco\SalesExchange\Model\ResourceModel\Exchange as ExchangeResource;
use Bonlineco\SalesExchange\Model\ResourceModel\History as HistoryResource;
use Bonlineco\SalesExchange\Model\ResourceModel\ReplacementItem as ReplacementItemResource;
use Bonlineco\SalesExchange\Model\Settlement\OperationKeys;
use Bonlineco\SalesExchange\Model\StateTransitionGuard;
use Bonlineco\SalesExchange\Model\VersionGuard;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\ShipmentItemCreationInterface;
use Magento\Sales\Api\Data\ShipmentInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;

class NativeLifecycleServicesTest extends TestCase
{
    private const INTENT_HASH =
        'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testMarkerReaderLeavesOrdinaryOrderUntouched(): void
    {
        self::assertNull((new MarkerReader())->execute($this->order()));
    }

    public function testMarkerReaderReturnsExactAllOrNothingIdentity(): void
    {
        $order = $this->order();
        $order->setData(Marker::EXCHANGE_ID, 7)
            ->setData(Marker::INTENT_HASH, self::INTENT_HASH);

        self::assertSame(
            ['exchange_id' => 7, 'intent_hash' => self::INTENT_HASH],
            (new MarkerReader())->execute($order)
        );
    }

    public function testMarkerReaderRejectsPartialIdentity(): void
    {
        $order = $this->order();
        $order->setData(Marker::EXCHANGE_ID, 7);
        $this->expectException(InvariantViolationException::class);

        (new MarkerReader())->execute($order);
    }

    public function testMarkedReplacementRefundFailsClosedBeforeAdapters(): void
    {
        $order = $this->order();
        $order->setEntityId(200)
            ->setData(Marker::EXCHANGE_ID, 7)
            ->setData(Marker::INTENT_HASH, self::INTENT_HASH)
            ->setData('payment_fee', '25.0000');
        $freshOrderLoader = $this->createMock(FreshOrderLoader::class);
        $freshOrderLoader->expects(self::once())
            ->method('execute')
            ->with(200)
            ->willReturn($order);
        $creditmemo = $this->createMock(CreditmemoInterface::class);
        $creditmemo->method('getOrderId')->willReturn(200);
        $this->expectException(InvariantViolationException::class);
        $this->expectExceptionMessage(
            'Native refunds of a replacement order are not supported'
        );

        (new ReplacementOrderGuard(
            $freshOrderLoader,
            new MarkerReader()
        ))->execute($creditmemo, $order);
    }

    public function testOpenSourceDeliveryProofDefaultIsUnavailable(): void
    {
        self::assertNull(
            (new UnavailableDeliveryProofProvider())->getProof($this->order())
        );
    }

    public function testFullShipmentRequestMatchesFrozenQuantities(): void
    {
        $validator = $this->shipmentValidator();
        $first = $this->shipmentRequestItem(501, '1.0000');
        $second = $this->shipmentRequestItem(502, '2.0000');

        $validator->assertFullRequest(
            [$second, $first],
            ['item_quantities_json' => '{"501":"1.0000","502":"2.0000"}']
        );

        self::assertTrue(true);
    }

    public function testPartialShipmentRequestFailsBeforeNativeWrite(): void
    {
        $this->expectException(InvariantViolationException::class);
        $this->expectExceptionMessage(
            'Partial replacement shipments are not supported'
        );

        $this->shipmentValidator()->assertFullRequest(
            [$this->shipmentRequestItem(501, '0.5000')],
            ['item_quantities_json' => '{"501":"1.0000"}']
        );
    }

    public function testShipmentReplayReturnsOneExactExistingDocument(): void
    {
        $exchange = $this->exchange(ReplacementStatus::SHIPPED);
        $exchange->setReturnStatus(ReturnStatus::ACCEPTED)
            ->setNativeReplacementAmount('120.0000')
            ->setBaseNativeReplacementAmount('120.0000');
        $replacementRows = [[
            ReplacementItemInterface::ENTITY_ID => 71,
            ReplacementItemInterface::REPLACEMENT_ORDER_ITEM_ID => 501,
        ]];
        $documentRows = [
            [DocumentLinkInterface::DOCUMENT_TYPE => DocumentType::ORDER],
            [
                DocumentLinkInterface::DOCUMENT_TYPE =>
                    DocumentType::SHIPMENT,
                DocumentLinkInterface::DOCUMENT_ID => 601,
            ],
        ];
        $orderSnapshot = [
            'amount' => '120.0000',
            'base_amount' => '120.0000',
            'expected_amount' => '120.0000',
            'item_quantities_json' => '{"501":"1.0000"}',
            'snapshot_hash' => str_repeat('b', 64),
            'item_ids' => [71 => 501],
        ];
        $nativeOrderValidator = $this->createMock(
            NativeOrderValidator::class
        );
        $nativeOrderValidator->expects(self::once())->method('snapshot')
            ->willReturn($orderSnapshot);
        $orderLinkValidator = $this->createMock(
            NativeOrderLinkValidator::class
        );
        $orderLinkValidator->expects(self::once())->method('execute');
        $shipment = $this->createMock(ShipmentInterface::class);
        $shipmentRepository = $this->createMock(
            ShipmentRepositoryInterface::class
        );
        $shipmentRepository->expects(self::once())->method('get')
            ->with(601)
            ->willReturn($shipment);
        $validator = $this->getMockBuilder(
            NativeShipmentValidator::class
        )->setConstructorArgs([
            $nativeOrderValidator,
            $orderLinkValidator,
            $this->createMock(StateTransitionGuardInterface::class),
            $shipmentRepository,
            new OperationKeys(),
            new DecimalMath(),
            new DecimalMath(4, 12),
            new Json(),
        ])->onlyMethods([
            'assertFullRequest',
            'shipmentSnapshot',
            'assertShipmentLink',
        ])->getMock();
        $validator->expects(self::once())->method('assertFullRequest');
        $shipmentSnapshot = [
            'item_quantities_json' => '{"501":"1.0000"}',
            'snapshot_hash' => str_repeat('c', 64),
            'document_status' => '1',
        ];
        $validator->expects(self::once())->method('shipmentSnapshot')
            ->willReturn($shipmentSnapshot);
        $validator->expects(self::once())->method('assertShipmentLink')
            ->with(
                $exchange,
                self::isInstanceOf(OrderInterface::class),
                $shipment,
                $documentRows[1],
                $shipmentSnapshot
            );

        self::assertSame(
            601,
            $validator->replayShipment(
                $exchange,
                $this->nativeOrder(),
                $this->nativeOrder(),
                [],
                $replacementRows,
                $documentRows,
                [],
                self::INTENT_HASH
            )
        );
    }

    public function testShipmentProofRejectsOperationKeyRebinding(): void
    {
        $validator = $this->shipmentProofValidator();
        $exchange = $this->exchange(ReplacementStatus::SHIPPED);
        $order = $this->nativeOrder();
        $shipment = $this->createMock(ShipmentInterface::class);
        $shipment->method('getEntityId')->willReturn(601);
        $shipment->method('getIncrementId')->willReturn('000000601');
        $snapshot = [
            'item_quantities_json' => '{"501":"1.0000"}',
            'snapshot_hash' => str_repeat('c', 64),
            'document_status' => '1',
        ];
        $row = $this->shipmentProofRow();
        $validator->assertShipmentLink(
            $exchange,
            $order,
            $shipment,
            $row,
            $snapshot
        );
        self::assertTrue(true);

        $row[DocumentLinkInterface::OPERATION_KEY] =
            'sales-exchange:replacement-shipment:v1:8';
        $this->expectException(InvariantViolationException::class);

        $validator->assertShipmentLink(
            $exchange,
            $order,
            $shipment,
            $row,
            $snapshot
        );
    }

    public function testCancellationWriterClearsNativeProjectionAtomically(): void
    {
        $exchange = $this->exchange(ReplacementStatus::ORDERED);
        $exchange->setNativeReturnCreditAmount('100.0000')
            ->setNativeReplacementAmount('120.0000')
            ->setBaseNativeReplacementAmount('120.0000')
            ->setBalanceAmount('20.0000');
        $exchangeResource = $this->createMock(ExchangeResource::class);
        $exchangeResource->expects(self::once())->method('save')
            ->with($exchange);
        $replacementResource = $this->createMock(
            ReplacementItemResource::class
        );
        $replacementResource->expects(self::once())->method('save')
            ->with(self::callback(
                static fn (ReplacementItemInterface $item): bool =>
                    $item->getReplacementOrderItemId() === null
                    && $item->getVersion() === 3
            ));
        $replacementFactory = $this->createMock(
            ReplacementItemFactory::class
        );
        $replacementFactory->method('create')
            ->willReturn($this->model(ReplacementItem::class));
        $historyFactory = $this->createMock(HistoryFactory::class);
        $historyFactory->method('create')
            ->willReturn($this->model(History::class));
        $historyResource = $this->createMock(HistoryResource::class);
        $historyResource->expects(self::once())->method('save');
        $returnProjection = $this->createMock(
            ReturnCreditProjection::class
        );
        $returnProjection->method('execute')->willReturn('100.0000');
        $balanceCalculator = $this->createMock(
            BalanceCalculatorInterface::class
        );
        $balanceCalculator->method('execute')->willReturn('-100.0000');
        $writer = new NativeCancellationWriter(
            $exchangeResource,
            $replacementResource,
            $replacementFactory,
            $historyFactory,
            $historyResource,
            new VersionGuard(),
            $returnProjection,
            $balanceCalculator,
            new DecimalMath()
        );

        $result = $writer->execute(
            $exchange,
            [ExchangeInterface::VERSION => 4],
            [[
                ReplacementItemInterface::ENTITY_ID => 71,
                ReplacementItemInterface::EXCHANGE_ID => 7,
                ReplacementItemInterface::REPLACEMENT_ORDER_ITEM_ID => 501,
                ReplacementItemInterface::VERSION => 2,
            ]],
            [],
            $this->nativeOrder()
        );

        self::assertTrue($result['changed']);
        self::assertSame(
            ReplacementStatus::CANCELLED,
            $exchange->getReplacementStatus()
        );
        self::assertSame('0.0000', $exchange->getNativeReplacementAmount());
        self::assertSame('-100.0000', $exchange->getBalanceAmount());
        self::assertSame(5, $exchange->getVersion());
    }

    public function testCancelledReplayRejectsAnActiveNativeOrder(): void
    {
        $exchange = $this->exchange(ReplacementStatus::CANCELLED);
        $exchange->setReturnStatus(ReturnStatus::ACCEPTED);
        $reflection = new \ReflectionClass(
            NativeCancellationValidator::class
        );
        /** @var NativeCancellationValidator $validator */
        $validator = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('transitionGuard')
            ->setValue($validator, new StateTransitionGuard());
        $reflection->getProperty('moneyMath')
            ->setValue($validator, new DecimalMath());
        $this->expectException(InvariantViolationException::class);
        $this->expectExceptionMessage(
            'requires the native replacement order to be cancelled already'
        );

        $validator->execute(
            $exchange,
            $this->nativeOrder(),
            $this->nativeOrder(),
            [],
            [],
            [],
            self::INTENT_HASH,
            false
        );
    }

    public function testDeliveryReplayRequiresTheSameImmutableProof(): void
    {
        $writer = $this->deliveryReplayWriter(
            'delivered;proof=order-status:delivered'
        );
        $result = $writer->execute(
            $this->exchange(ReplacementStatus::DELIVERED),
            [ExchangeInterface::VERSION => 4],
            [],
            [],
            [],
            'order-status:delivered'
        );

        self::assertFalse($result['changed']);
    }

    public function testDeliveryWriterPersistsProofAndAdvancesOnce(): void
    {
        $exchange = $this->exchange(ReplacementStatus::SHIPPED);
        $exchangeResource = $this->createMock(ExchangeResource::class);
        $exchangeResource->expects(self::once())->method('save')
            ->with($exchange);
        $historyFactory = $this->createMock(HistoryFactory::class);
        $historyFactory->method('create')
            ->willReturn($this->model(History::class));
        $historyResource = $this->createMock(HistoryResource::class);
        $historyResource->expects(self::once())
            ->method('getRowsByExchangeIdForUpdate')
            ->with(7)
            ->willReturn([]);
        $historyResource->expects(self::once())->method('save')
            ->with(self::callback(
                static fn (HistoryInterface $history): bool =>
                    $history->getAction() === 'replacement_delivery_proven'
                    && $history->getToValue()
                        === 'delivered;proof=order-status:delivered'
                    && $history->getActorType() === ActorType::INTEGRATION
            ));
        $completionValidator = $this->createMock(
            CompletionValidator::class
        );
        $completionValidator->expects(self::never())->method('execute');
        $writer = new NativeDeliveryWriter(
            $exchangeResource,
            $historyFactory,
            $historyResource,
            new VersionGuard(),
            $this->createMock(StateTransitionGuardInterface::class),
            $completionValidator
        );

        $result = $writer->execute(
            $exchange,
            [ExchangeInterface::VERSION => 4],
            [],
            [],
            [],
            'order-status:delivered'
        );

        self::assertTrue($result['changed']);
        self::assertSame(
            ReplacementStatus::DELIVERED,
            $exchange->getReplacementStatus()
        );
        self::assertSame(5, $exchange->getVersion());
        self::assertSame(
            ExchangeStatus::IN_PROGRESS,
            $exchange->getExchangeStatus()
        );
    }

    public function testDeliveryReplayRejectsProofRebinding(): void
    {
        $writer = $this->deliveryReplayWriter(
            'delivered;proof=order-status:delivered'
        );
        $this->expectException(InvariantViolationException::class);

        $writer->execute(
            $this->exchange(ReplacementStatus::DELIVERED),
            [ExchangeInterface::VERSION => 4],
            [],
            [],
            [],
            'courier:other-reference'
        );
    }

    private function shipmentValidator(): NativeShipmentValidator
    {
        $reflection = new \ReflectionClass(NativeShipmentValidator::class);
        /** @var NativeShipmentValidator $validator */
        $validator = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('quantityMath')
            ->setValue($validator, new DecimalMath(4, 12));
        $reflection->getProperty('serializer')
            ->setValue($validator, new Json());

        return $validator;
    }

    private function shipmentProofValidator(): NativeShipmentValidator
    {
        $reflection = new \ReflectionClass(NativeShipmentValidator::class);
        /** @var NativeShipmentValidator $validator */
        $validator = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('operationKeys')
            ->setValue($validator, new OperationKeys());

        return $validator;
    }

    /**
     * @return array<string, mixed>
     */
    private function shipmentProofRow(): array
    {
        return [
            DocumentLinkInterface::EXCHANGE_ID => 7,
            DocumentLinkInterface::DOCUMENT_TYPE => DocumentType::SHIPMENT,
            DocumentLinkInterface::DOCUMENT_ID => 601,
            DocumentLinkInterface::INCREMENT_ID => '000000601',
            DocumentLinkInterface::OPERATION_KEY =>
                'sales-exchange:replacement-shipment:v1:7',
            DocumentLinkInterface::ITEM_QUANTITIES_JSON =>
                '{"501":"1.0000"}',
            DocumentLinkInterface::SNAPSHOT_HASH => str_repeat('c', 64),
            DocumentLinkInterface::AMOUNT => '0.0000',
            DocumentLinkInterface::EXPECTED_AMOUNT => '0.0000',
            DocumentLinkInterface::BASE_AMOUNT => '0.0000',
            DocumentLinkInterface::CURRENCY_CODE => 'EGP',
            DocumentLinkInterface::BASE_CURRENCY_CODE => 'EGP',
            DocumentLinkInterface::DOCUMENT_STATUS => '1',
        ];
    }

    private function shipmentRequestItem(
        int $orderItemId,
        string $quantity
    ): ShipmentItemCreationInterface {
        $item = $this->createMock(ShipmentItemCreationInterface::class);
        $item->method('getOrderItemId')->willReturn($orderItemId);
        $item->method('getQty')->willReturn((float)$quantity);

        return $item;
    }

    private function deliveryReplayWriter(string $toValue): NativeDeliveryWriter
    {
        $historyResource = $this->createMock(HistoryResource::class);
        $historyResource->method('getRowsByExchangeIdForUpdate')
            ->with(7)
            ->willReturn([[
                HistoryInterface::ACTION => 'replacement_delivery_proven',
                HistoryInterface::TO_VALUE => $toValue,
                HistoryInterface::ACTOR_TYPE => ActorType::INTEGRATION,
            ]]);
        $reflection = new \ReflectionClass(NativeDeliveryWriter::class);
        /** @var NativeDeliveryWriter $writer */
        $writer = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('historyResource')
            ->setValue($writer, $historyResource);

        return $writer;
    }

    private function exchange(string $replacementStatus): Exchange
    {
        $exchange = $this->model(Exchange::class);
        $exchange->setEntityId(7)
            ->setExchangeStatus(ExchangeStatus::IN_PROGRESS)
            ->setReplacementStatus($replacementStatus)
            ->setSettlementStatus(SettlementStatus::PENDING)
            ->setFeeAmount('0.0000')
            ->setVersion(4);

        return $exchange;
    }

    private function nativeOrder(): OrderInterface
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getIncrementId')->willReturn('000000200');
        $order->method('getOrderCurrencyCode')->willReturn('EGP');
        $order->method('getBaseCurrencyCode')->willReturn('EGP');

        return $order;
    }

    private function order(): Order
    {
        return $this->model(Order::class);
    }

    /**
     * @template T of object
     * @param class-string<T> $className
     * @return T
     */
    private function model(string $className): object
    {
        return (new \ReflectionClass($className))
            ->newInstanceWithoutConstructor();
    }
}
