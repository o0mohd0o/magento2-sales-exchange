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

/**
 * Prove that one native cancellation belongs to the immutable order handoff.
 */
class NativeCancellationValidator
{
    private NativeOrderValidator $nativeOrderValidator;

    private NativeOrderLinkValidator $orderLinkValidator;

    private StateTransitionGuardInterface $transitionGuard;

    private DecimalMath $moneyMath;

    public function __construct(
        NativeOrderValidator $nativeOrderValidator,
        NativeOrderLinkValidator $orderLinkValidator,
        StateTransitionGuardInterface $transitionGuard,
        DecimalMath $moneyMath
    ) {
        $this->nativeOrderValidator = $nativeOrderValidator;
        $this->orderLinkValidator = $orderLinkValidator;
        $this->transitionGuard = $transitionGuard;
        $this->moneyMath = $moneyMath;
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
    public function execute(
        ExchangeInterface $exchange,
        OrderInterface $replacementOrder,
        OrderInterface $originalOrder,
        array $replacementRows,
        array $documentRows,
        array $settlementRows,
        string $intentHash,
        bool $nativeIsCancelled
    ): array {
        $this->assertWorkflow($exchange, $settlementRows);
        if ($exchange->getReplacementStatus()
                === ReplacementStatus::CANCELLED
            && !$nativeIsCancelled
        ) {
            throw new InvariantViolationException(
                __(
                    'A cancelled replacement replay requires the native '
                    . 'replacement order to be cancelled already.'
                )
            );
        }
        $snapshot = $nativeIsCancelled
            ? $this->nativeOrderValidator->cancelledSnapshot(
                $replacementOrder,
                $originalOrder,
                $exchange,
                $replacementRows,
                $intentHash
            )
            : $this->nativeOrderValidator->snapshot(
                $replacementOrder,
                $originalOrder,
                $exchange,
                $replacementRows,
                $intentHash
            );
        $this->assertDocuments(
            $exchange,
            $replacementOrder,
            $documentRows,
            $snapshot
        );
        $this->assertProjection($exchange, $replacementRows, $snapshot);

        return $snapshot;
    }

    /**
     * @param array<int, array<string, mixed>> $settlementRows
     */
    private function assertWorkflow(
        ExchangeInterface $exchange,
        array $settlementRows
    ): void {
        $this->transitionGuard->executeNativeReplacementCancellation(
            $exchange->getReplacementStatus()
        );
        if ($this->moneyMath->compare($exchange->getFeeAmount(), '0') !== 0
            || !in_array(
                $exchange->getReturnStatus(),
                [ReturnStatus::ACCEPTED, ReturnStatus::PARTIALLY_ACCEPTED],
                true
            )
        ) {
            throw new InvariantViolationException(
                __('The replacement cancellation workflow snapshot is invalid.')
            );
        }

        if ($exchange->getReplacementStatus() === ReplacementStatus::ORDERED) {
            if ($exchange->getExchangeStatus() !== ExchangeStatus::IN_PROGRESS
                || $exchange->getSettlementStatus()
                    !== SettlementStatus::PENDING
                || $settlementRows !== []
            ) {
                throw new InvariantViolationException(
                    __(
                        'An ordered replacement can be cancelled only before '
                        . 'settlement creates a durable posting.'
                    )
                );
            }

            return;
        }

        $settlementStatus = $exchange->getSettlementStatus();
        if (!in_array(
            $exchange->getExchangeStatus(),
            [ExchangeStatus::IN_PROGRESS, ExchangeStatus::COMPLETED],
            true
        ) || !in_array(
            $settlementStatus,
            [
                SettlementStatus::PENDING,
                SettlementStatus::BALANCED,
                SettlementStatus::REFUND_ISSUED,
            ],
            true
        ) || ($settlementStatus === SettlementStatus::PENDING
            && $settlementRows !== [])
        ) {
            throw new InvariantViolationException(
                __('The cancelled replacement replay snapshot is invalid.')
            );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $documentRows
     * @param array{
     *     amount: string,
     *     base_amount: string,
     *     expected_amount: string,
     *     item_quantities_json: string,
     *     snapshot_hash: string,
     *     item_ids: array<int, int>
     * } $snapshot
     */
    private function assertDocuments(
        ExchangeInterface $exchange,
        OrderInterface $order,
        array $documentRows,
        array $snapshot
    ): void {
        $this->orderLinkValidator->execute(
            $exchange,
            $order,
            $documentRows,
            $snapshot
        );
        foreach ($documentRows as $row) {
            $type = (string)($row[
                DocumentLinkInterface::DOCUMENT_TYPE
            ] ?? '');
            if (in_array(
                $type,
                [DocumentType::INVOICE, DocumentType::SHIPMENT],
                true
            )) {
                throw new InvariantViolationException(
                    __(
                        'An invoiced or shipped replacement order cannot use '
                        . 'the native cancellation compensation.'
                    )
                );
            }
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
    private function assertProjection(
        ExchangeInterface $exchange,
        array $replacementRows,
        array $snapshot
    ): void {
        $cancelled = $exchange->getReplacementStatus()
            === ReplacementStatus::CANCELLED;
        if ($this->moneyMath->compare(
            $exchange->getNativeReplacementAmount(),
            $cancelled ? '0' : $snapshot['amount']
        ) !== 0 || $this->moneyMath->compare(
            $exchange->getBaseNativeReplacementAmount(),
            $cancelled ? '0' : $snapshot['base_amount']
        ) !== 0) {
            throw new InvariantViolationException(
                __('The replacement cancellation native totals are inconsistent.')
            );
        }

        foreach ($replacementRows as $row) {
            $replacementItemId = (int)($row[
                ReplacementItemInterface::ENTITY_ID
            ] ?? 0);
            $persistedOrderItemId = $row[
                ReplacementItemInterface::REPLACEMENT_ORDER_ITEM_ID
            ] ?? null;
            $expectedOrderItemId = $snapshot['item_ids'][
                $replacementItemId
            ] ?? null;
            if ($replacementItemId <= 0
                || $expectedOrderItemId === null
                || ($cancelled && $persistedOrderItemId !== null)
                || (!$cancelled
                    && (int)$persistedOrderItemId !== $expectedOrderItemId)
            ) {
                throw new InvariantViolationException(
                    __('The replacement cancellation item handoff is inconsistent.')
                );
            }
        }
    }
}
