<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\ReplacementOrder;

use Bonlineco\SalesExchange\Api\Data\DocumentLinkInterface;
use Bonlineco\SalesExchange\Api\Data\ExchangeInterface;
use Bonlineco\SalesExchange\Api\Data\ReplacementItemInterface;
use Bonlineco\SalesExchange\Api\DocumentType;
use Bonlineco\SalesExchange\Api\StateTransitionGuardInterface;
use Bonlineco\SalesExchange\Api\Status\ExchangeStatus;
use Bonlineco\SalesExchange\Api\Status\ReplacementStatus;
use Bonlineco\SalesExchange\Api\Status\ReturnStatus;
use Bonlineco\SalesExchange\Api\Status\SettlementStatus;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Bonlineco\SalesExchange\Model\Settlement\OperationKeys;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\Data\ShipmentInterface;
use Magento\Sales\Api\Data\ShipmentItemCreationInterface;
use Magento\Sales\Api\Data\ShipmentItemInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;

/**
 * Validate a full native shipment before advancing replacement fulfillment.
 */
class NativeShipmentValidator
{
    private NativeOrderValidator $nativeOrderValidator;

    private NativeOrderLinkValidator $orderLinkValidator;

    private StateTransitionGuardInterface $transitionGuard;

    private ShipmentRepositoryInterface $shipmentRepository;

    private OperationKeys $operationKeys;

    private DecimalMath $moneyMath;

    private DecimalMath $quantityMath;

    private SerializerInterface $serializer;

    public function __construct(
        NativeOrderValidator $nativeOrderValidator,
        NativeOrderLinkValidator $orderLinkValidator,
        StateTransitionGuardInterface $transitionGuard,
        ShipmentRepositoryInterface $shipmentRepository,
        OperationKeys $operationKeys,
        DecimalMath $moneyMath,
        DecimalMath $quantityMath,
        SerializerInterface $serializer
    ) {
        $this->nativeOrderValidator = $nativeOrderValidator;
        $this->orderLinkValidator = $orderLinkValidator;
        $this->transitionGuard = $transitionGuard;
        $this->shipmentRepository = $shipmentRepository;
        $this->operationKeys = $operationKeys;
        $this->moneyMath = $moneyMath;
        $this->quantityMath = $quantityMath;
        $this->serializer = $serializer;
    }

    /**
     * @param array<int, array<string, mixed>> $replacementRows
     * @param array<int, array<string, mixed>> $documentRows
     * @param array<int, array<string, mixed>> $settlementRows
     * @return array{
     *     amount: string,
     *     base_amount: string,
     *     expected_amount: string,
     *     item_quantities_json: string,
     *     snapshot_hash: string,
     *     item_ids: array<int, int>
     * }
     */
    public function beforeShipment(
        ExchangeInterface $exchange,
        OrderInterface $order,
        OrderInterface $originalOrder,
        array $replacementRows,
        array $documentRows,
        array $settlementRows,
        string $intentHash
    ): array {
        $this->assertWorkflow($exchange, $settlementRows);
        foreach ($documentRows as $row) {
            if ((string)($row[
                DocumentLinkInterface::DOCUMENT_TYPE
            ] ?? '') === DocumentType::SHIPMENT) {
                throw new InvariantViolationException(
                    __('The replacement already has a native shipment link.')
                );
            }
        }

        $snapshot = $this->nativeOrderValidator->snapshot(
            $order,
            $originalOrder,
            $exchange,
            $replacementRows,
            $intentHash
        );
        $this->orderLinkValidator->execute(
            $exchange,
            $order,
            $documentRows,
            $snapshot
        );
        $this->assertReplacementProjection(
            $exchange,
            $replacementRows,
            $snapshot
        );

        return $snapshot;
    }

    /**
     * Return the one exact committed shipment for a safe client retry.
     *
     * @param ShipmentItemCreationInterface[] $items
     * @param array<int, array<string, mixed>> $replacementRows
     * @param array<int, array<string, mixed>> $documentRows
     * @param array<int, array<string, mixed>> $settlementRows
     */
    public function replayShipment(
        ExchangeInterface $exchange,
        OrderInterface $order,
        OrderInterface $originalOrder,
        array $items,
        array $replacementRows,
        array $documentRows,
        array $settlementRows,
        string $intentHash
    ): int {
        $this->assertReplayWorkflow($exchange, $settlementRows);
        $orderSnapshot = $this->nativeOrderValidator->snapshot(
            $order,
            $originalOrder,
            $exchange,
            $replacementRows,
            $intentHash
        );
        $this->orderLinkValidator->execute(
            $exchange,
            $order,
            $documentRows,
            $orderSnapshot
        );
        $this->assertReplacementProjection(
            $exchange,
            $replacementRows,
            $orderSnapshot
        );
        $this->assertFullRequest($items, $orderSnapshot);

        $shipmentRows = [];
        foreach ($documentRows as $row) {
            if ((string)($row[
                DocumentLinkInterface::DOCUMENT_TYPE
            ] ?? '') === DocumentType::SHIPMENT) {
                $shipmentRows[] = $row;
            }
        }
        if (count($shipmentRows) !== 1) {
            throw new InvariantViolationException(
                __('Shipment replay requires exactly one immutable shipment link.')
            );
        }
        $row = $shipmentRows[0];
        $shipmentId = (int)($row[
            DocumentLinkInterface::DOCUMENT_ID
        ] ?? 0);
        if ($shipmentId <= 0) {
            throw new InvariantViolationException(
                __('The immutable replacement shipment identity is invalid.')
            );
        }
        $shipment = $this->shipmentRepository->get($shipmentId);
        $shipmentSnapshot = $this->shipmentSnapshot(
            $shipment,
            $order,
            $orderSnapshot
        );
        $this->assertShipmentLink(
            $exchange,
            $order,
            $shipment,
            $row,
            $shipmentSnapshot
        );

        return $shipmentId;
    }

    /**
     * @param array{
     *     amount: string,
     *     base_amount: string,
     *     expected_amount: string,
     *     item_quantities_json: string,
     *     snapshot_hash: string,
     *     item_ids: array<int, int>
     * } $orderSnapshot
     * @return array{
     *     item_quantities_json: string,
     *     snapshot_hash: string,
     *     document_status: string|null
     * }
     */
    public function shipmentSnapshot(
        ShipmentInterface $shipment,
        OrderInterface $order,
        array $orderSnapshot
    ): array {
        $shipmentId = (int)$shipment->getEntityId();
        $orderId = (int)$order->getEntityId();
        $incrementId = trim((string)$shipment->getIncrementId());
        if ($shipmentId <= 0
            || $orderId <= 0
            || (int)$shipment->getOrderId() !== $orderId
            || $incrementId === ''
        ) {
            throw new InvariantViolationException(
                __('The native replacement shipment identity is invalid.')
            );
        }

        $expected = $this->expectedQuantities($orderSnapshot);

        $shipped = [];
        foreach ((array)$shipment->getItems() as $item) {
            if (!$item instanceof ShipmentItemInterface) {
                throw new InvariantViolationException(
                    __('The native replacement shipment item is invalid.')
                );
            }
            $orderItemId = (int)$item->getOrderItemId();
            if ($orderItemId <= 0
                || !isset($expected[$orderItemId])
                || isset($shipped[$orderItemId])
            ) {
                throw new InvariantViolationException(
                    __('The native shipment item mapping is invalid or partial.')
                );
            }
            $shipped[$orderItemId] = $this->quantityMath
                ->assertNonNegative(
                    (string)$item->getQty(),
                    'Native shipment item quantity'
                );
        }
        ksort($shipped, SORT_NUMERIC);
        if (array_keys($expected) !== array_keys($shipped)) {
            throw new InvariantViolationException(
                __('The replacement shipment must contain every order item.')
            );
        }
        foreach ($expected as $itemId => $quantity) {
            if ($this->quantityMath->compare(
                $quantity,
                $shipped[$itemId]
            ) !== 0) {
                throw new InvariantViolationException(
                    __('Partial replacement shipments are not supported.')
                );
            }
        }
        $this->assertOrderFullyShipped($order, $expected);

        $total = '0.0000';
        foreach ($shipped as $quantity) {
            $total = $this->quantityMath->add($total, $quantity);
        }
        if ($this->quantityMath->compare(
            (string)$shipment->getTotalQty(),
            $total
        ) !== 0) {
            throw new InvariantViolationException(
                __('The native shipment total quantity is inconsistent.')
            );
        }

        $quantitiesJson = $this->serializer->serialize($shipped);
        $fingerprint = [
            'shipment_id' => $shipmentId,
            'increment_id' => $incrementId,
            'order_id' => $orderId,
            'item_quantities' => $shipped,
        ];
        $status = $shipment->getShipmentStatus();

        return [
            'item_quantities_json' => $quantitiesJson,
            'snapshot_hash' => hash(
                'sha256',
                $this->serializer->serialize($fingerprint)
            ),
            'document_status' => $status === null
                ? null
                : (string)$status,
        ];
    }

    /**
     * Reject explicit partial requests before Magento saves a shipment.
     *
     * An empty item request is Magento's canonical "ship all" request.
     *
     * @param ShipmentItemCreationInterface[] $items
     * @param array{item_quantities_json: string} $orderSnapshot
     */
    public function assertFullRequest(array $items, array $orderSnapshot): void
    {
        if ($items === []) {
            return;
        }

        $expected = $this->expectedQuantities($orderSnapshot);
        $requested = [];
        foreach ($items as $item) {
            if (!$item instanceof ShipmentItemCreationInterface) {
                throw new InvariantViolationException(
                    __('The replacement shipment request item is invalid.')
                );
            }
            $orderItemId = (int)$item->getOrderItemId();
            if (!isset($expected[$orderItemId])
                || isset($requested[$orderItemId])
            ) {
                throw new InvariantViolationException(
                    __('The replacement shipment request mapping is invalid.')
                );
            }
            $requested[$orderItemId] = $this->quantityMath
                ->assertNonNegative(
                    (string)$item->getQty(),
                    'Requested replacement shipment quantity'
                );
        }
        ksort($requested, SORT_NUMERIC);
        if (array_keys($expected) !== array_keys($requested)) {
            throw new InvariantViolationException(
                __('The replacement shipment request must contain every item.')
            );
        }
        foreach ($expected as $itemId => $quantity) {
            if ($this->quantityMath->compare(
                $quantity,
                $requested[$itemId]
            ) !== 0) {
                throw new InvariantViolationException(
                    __('Partial replacement shipments are not supported.')
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param array{
     *     item_quantities_json: string,
     *     snapshot_hash: string,
     *     document_status: string|null
     * } $snapshot
     */
    public function assertShipmentLink(
        ExchangeInterface $exchange,
        OrderInterface $order,
        ShipmentInterface $shipment,
        array $row,
        array $snapshot
    ): void {
        $matches = (int)($row[
            DocumentLinkInterface::EXCHANGE_ID
        ] ?? 0) === (int)$exchange->getEntityId()
            && (string)($row[
                DocumentLinkInterface::DOCUMENT_TYPE
            ] ?? '') === DocumentType::SHIPMENT
            && (int)($row[
                DocumentLinkInterface::DOCUMENT_ID
            ] ?? 0) === (int)$shipment->getEntityId()
            && (string)($row[
                DocumentLinkInterface::INCREMENT_ID
            ] ?? '') === (string)$shipment->getIncrementId()
            && (string)($row[
                DocumentLinkInterface::OPERATION_KEY
            ] ?? '') === $this->operationKeys->replacementShipment(
                (int)$exchange->getEntityId()
            )
            && (string)($row[
                DocumentLinkInterface::ITEM_QUANTITIES_JSON
            ] ?? '') === $snapshot['item_quantities_json']
            && is_string($row[
                DocumentLinkInterface::SNAPSHOT_HASH
            ] ?? null)
            && hash_equals(
                $snapshot['snapshot_hash'],
                (string)$row[DocumentLinkInterface::SNAPSHOT_HASH]
            )
            && (string)($row[
                DocumentLinkInterface::AMOUNT
            ] ?? '') === '0.0000'
            && (string)($row[
                DocumentLinkInterface::EXPECTED_AMOUNT
            ] ?? '') === '0.0000'
            && (string)($row[
                DocumentLinkInterface::BASE_AMOUNT
            ] ?? '') === '0.0000'
            && (string)($row[
                DocumentLinkInterface::CURRENCY_CODE
            ] ?? '') === (string)$order->getOrderCurrencyCode()
            && (string)($row[
                DocumentLinkInterface::BASE_CURRENCY_CODE
            ] ?? '') === (string)$order->getBaseCurrencyCode()
            && ($row[
                DocumentLinkInterface::DOCUMENT_STATUS
            ] ?? null) === $snapshot['document_status'];
        if (!$matches) {
            throw new InvariantViolationException(
                __('The native shipment differs from its immutable proof link.')
            );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $replacementRows
     * @param array{
     *     amount: string,
     *     base_amount: string,
     *     expected_amount: string,
     *     item_quantities_json: string,
     *     snapshot_hash: string,
     *     item_ids: array<int, int>
     * } $snapshot
     */
    private function assertReplacementProjection(
        ExchangeInterface $exchange,
        array $replacementRows,
        array $snapshot
    ): void {
        if ($this->moneyMath->compare(
            $exchange->getNativeReplacementAmount(),
            $snapshot['amount']
        ) !== 0 || $this->moneyMath->compare(
            $exchange->getBaseNativeReplacementAmount(),
            $snapshot['base_amount']
        ) !== 0) {
            throw new InvariantViolationException(
                __('The replacement shipment native totals are inconsistent.')
            );
        }
        foreach ($replacementRows as $row) {
            $replacementItemId = (int)($row[
                ReplacementItemInterface::ENTITY_ID
            ] ?? 0);
            if ($replacementItemId <= 0
                || !isset($snapshot['item_ids'][$replacementItemId])
                || (int)($row[
                    ReplacementItemInterface::REPLACEMENT_ORDER_ITEM_ID
                ] ?? 0) !== $snapshot['item_ids'][$replacementItemId]
            ) {
                throw new InvariantViolationException(
                    __('The replacement shipment item handoff is inconsistent.')
                );
            }
        }
    }

    /**
     * @param array<int, string> $expected
     */
    private function assertOrderFullyShipped(
        OrderInterface $order,
        array $expected
    ): void {
        $items = [];
        foreach ((array)$order->getItems() as $item) {
            if (!$item instanceof OrderItemInterface) {
                continue;
            }
            $itemId = (int)$item->getItemId();
            if (isset($expected[$itemId])) {
                $items[$itemId] = $item;
            }
        }
        ksort($items, SORT_NUMERIC);
        if (array_keys($expected) !== array_keys($items)) {
            throw new InvariantViolationException(
                __('The shipped replacement order item set is invalid.')
            );
        }
        foreach ($items as $item) {
            if ($this->quantityMath->compare(
                (string)$item->getQtyShipped(),
                (string)$item->getQtyOrdered()
            ) !== 0 || $this->quantityMath->compare(
                (string)$item->getQtyCanceled(),
                '0'
            ) !== 0 || $this->quantityMath->compare(
                (string)$item->getQtyRefunded(),
                '0'
            ) !== 0) {
                throw new InvariantViolationException(
                    __('The native replacement order is not fully shipped.')
                );
            }
        }
    }

    /**
     * @param array{item_quantities_json: string} $orderSnapshot
     * @return array<int, string>
     */
    private function expectedQuantities(array $orderSnapshot): array
    {
        /** @var array<int|string, mixed> $decoded */
        $decoded = $this->serializer->unserialize(
            $orderSnapshot['item_quantities_json']
        );
        $expected = [];
        foreach ($decoded as $itemId => $quantity) {
            $itemId = (int)$itemId;
            if ($itemId <= 0 || isset($expected[$itemId])) {
                throw new InvariantViolationException(
                    __('The replacement order quantity snapshot is invalid.')
                );
            }
            $expected[$itemId] = $this->quantityMath->assertNonNegative(
                (string)$quantity,
                'Replacement shipment quantity'
            );
        }
        if ($expected === []) {
            throw new InvariantViolationException(
                __('A replacement shipment requires order item quantities.')
            );
        }
        ksort($expected, SORT_NUMERIC);

        return $expected;
    }

    /**
     * @param array<int, array<string, mixed>> $settlementRows
     */
    private function assertWorkflow(
        ExchangeInterface $exchange,
        array $settlementRows
    ): void {
        $this->transitionGuard->executeNativeReplacementShipment(
            $exchange->getReplacementStatus()
        );
        $settlementStatus = $exchange->getSettlementStatus();
        if ($exchange->getExchangeStatus() !== ExchangeStatus::IN_PROGRESS
            || !in_array(
                $exchange->getReturnStatus(),
                [ReturnStatus::ACCEPTED, ReturnStatus::PARTIALLY_ACCEPTED],
                true
            )
            || !in_array(
                $settlementStatus,
                [
                    SettlementStatus::PENDING,
                    SettlementStatus::BALANCED,
                    SettlementStatus::PAYMENT_RECEIVED,
                    SettlementStatus::REFUND_ISSUED,
                ],
                true
            )
            || ($settlementStatus === SettlementStatus::PENDING
                && $settlementRows !== [])
            || $this->moneyMath->compare(
                $exchange->getFeeAmount(),
                '0'
            ) !== 0
        ) {
            throw new InvariantViolationException(
                __('The replacement shipment workflow snapshot is invalid.')
            );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $settlementRows
     */
    private function assertReplayWorkflow(
        ExchangeInterface $exchange,
        array $settlementRows
    ): void {
        $replacementStatus = $exchange->getReplacementStatus();
        $exchangeStatus = $exchange->getExchangeStatus();
        $settlementStatus = $exchange->getSettlementStatus();
        $statusMatches = $replacementStatus === ReplacementStatus::SHIPPED
            ? $exchangeStatus === ExchangeStatus::IN_PROGRESS
            : $replacementStatus === ReplacementStatus::DELIVERED
                && in_array(
                    $exchangeStatus,
                    [ExchangeStatus::IN_PROGRESS, ExchangeStatus::COMPLETED],
                    true
                );
        if (!$statusMatches
            || !in_array(
                $exchange->getReturnStatus(),
                [ReturnStatus::ACCEPTED, ReturnStatus::PARTIALLY_ACCEPTED],
                true
            )
            || !in_array(
                $settlementStatus,
                [
                    SettlementStatus::PENDING,
                    SettlementStatus::BALANCED,
                    SettlementStatus::PAYMENT_RECEIVED,
                    SettlementStatus::REFUND_ISSUED,
                ],
                true
            )
            || ($settlementStatus === SettlementStatus::PENDING
                && $settlementRows !== [])
            || $this->moneyMath->compare(
                $exchange->getFeeAmount(),
                '0'
            ) !== 0
        ) {
            throw new InvariantViolationException(
                __('The replacement shipment replay snapshot is invalid.')
            );
        }
    }
}
