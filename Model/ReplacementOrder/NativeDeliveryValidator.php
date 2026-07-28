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
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;

/**
 * Rebuild the full shipment proof before accepting adapter delivery proof.
 */
class NativeDeliveryValidator
{
    private NativeOrderValidator $nativeOrderValidator;

    private NativeOrderLinkValidator $orderLinkValidator;

    private NativeShipmentValidator $shipmentValidator;

    private ShipmentRepositoryInterface $shipmentRepository;

    private StateTransitionGuardInterface $transitionGuard;

    private DecimalMath $moneyMath;

    public function __construct(
        NativeOrderValidator $nativeOrderValidator,
        NativeOrderLinkValidator $orderLinkValidator,
        NativeShipmentValidator $shipmentValidator,
        ShipmentRepositoryInterface $shipmentRepository,
        StateTransitionGuardInterface $transitionGuard,
        DecimalMath $moneyMath
    ) {
        $this->nativeOrderValidator = $nativeOrderValidator;
        $this->orderLinkValidator = $orderLinkValidator;
        $this->shipmentValidator = $shipmentValidator;
        $this->shipmentRepository = $shipmentRepository;
        $this->transitionGuard = $transitionGuard;
        $this->moneyMath = $moneyMath;
    }

    /**
     * @param array<int, array<string, mixed>> $replacementRows
     * @param array<int, array<string, mixed>> $documentRows
     * @param array<int, array<string, mixed>> $settlementRows
     */
    public function execute(
        ExchangeInterface $exchange,
        OrderInterface $order,
        OrderInterface $originalOrder,
        array $replacementRows,
        array $documentRows,
        array $settlementRows,
        string $intentHash
    ): void {
        $this->assertWorkflow($exchange, $settlementRows);
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
                __('Delivery requires exactly one trusted full shipment link.')
            );
        }
        $row = $shipmentRows[0];
        $shipment = $this->shipmentRepository->get(
            (int)($row[DocumentLinkInterface::DOCUMENT_ID] ?? 0)
        );
        $snapshot = $this->shipmentValidator->shipmentSnapshot(
            $shipment,
            $order,
            $orderSnapshot
        );
        $this->shipmentValidator->assertShipmentLink(
            $exchange,
            $order,
            $shipment,
            $row,
            $snapshot
        );
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
                __('The delivered replacement native totals are inconsistent.')
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
                    __('The delivered replacement item handoff is inconsistent.')
                );
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $settlementRows
     */
    private function assertWorkflow(
        ExchangeInterface $exchange,
        array $settlementRows
    ): void {
        $this->transitionGuard->executeProvenReplacementDelivery(
            $exchange->getReplacementStatus()
        );
        $settlementStatus = $exchange->getSettlementStatus();
        if (!in_array(
            $exchange->getExchangeStatus(),
            [ExchangeStatus::IN_PROGRESS, ExchangeStatus::COMPLETED],
            true
        ) || !in_array(
            $exchange->getReturnStatus(),
            [ReturnStatus::ACCEPTED, ReturnStatus::PARTIALLY_ACCEPTED],
            true
        ) || !in_array(
            $settlementStatus,
            [
                SettlementStatus::PENDING,
                SettlementStatus::BALANCED,
                SettlementStatus::PAYMENT_RECEIVED,
                SettlementStatus::REFUND_ISSUED,
            ],
            true
        ) || ($settlementStatus === SettlementStatus::PENDING
            && $settlementRows !== [])
            || $this->moneyMath->compare($exchange->getFeeAmount(), '0') !== 0
        ) {
            throw new InvariantViolationException(
                __('The replacement delivery workflow snapshot is invalid.')
            );
        }
    }
}
