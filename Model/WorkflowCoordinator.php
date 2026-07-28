<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model;

use Bonlineco\SalesExchange\Api\Data\ExchangeInterface;
use Bonlineco\SalesExchange\Api\Data\ReplacementItemInterface;
use Bonlineco\SalesExchange\Api\Data\ReturnItemInterface;
use Bonlineco\SalesExchange\Api\Data\SettlementInterface;
use Bonlineco\SalesExchange\Api\Settlement\EntryStatus;
use Bonlineco\SalesExchange\Api\Status\ExchangeStatus;
use Bonlineco\SalesExchange\Api\Status\ReplacementStatus;
use Bonlineco\SalesExchange\Api\Status\ReturnStatus;
use Bonlineco\SalesExchange\Api\Status\SettlementStatus;
use Bonlineco\SalesExchange\Api\Status\StateDimension;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;

/**
 * Coordinate transitions across the four independently stored dimensions.
 */
class WorkflowCoordinator
{
    private DecimalMath $moneyMath;

    private DecimalMath $quantityMath;

    public function __construct(DecimalMath $moneyMath, DecimalMath $quantityMath)
    {
        $this->moneyMath = $moneyMath;
        $this->quantityMath = $quantityMath;
    }

    /**
     * @param array<int, array<string, mixed>> $returnRows
     * @param array<int, array<string, mixed>> $replacementRows
     * @param array<int, array<string, mixed>> $settlementRows
     * @throws InvariantViolationException
     */
    public function execute(
        ExchangeInterface $exchange,
        string $dimension,
        string $toStatus,
        array $returnRows,
        array $replacementRows,
        array $settlementRows
    ): void {
        if ($dimension !== StateDimension::EXCHANGE
            && in_array($exchange->getExchangeStatus(), ExchangeStatus::terminal(), true)
        ) {
            throw new InvariantViolationException(
                __('A closed exchange cannot transition a subordinate workflow.')
            );
        }

        if ($dimension === StateDimension::RETURN) {
            if ($toStatus === ReturnStatus::CANCELLED) {
                $this->assertReturnCancellationSafe($exchange, $returnRows);
            }
            $this->assertReturnOrdering($exchange, $toStatus);
        } elseif ($dimension === StateDimension::REPLACEMENT) {
            if ($toStatus === ReplacementStatus::CANCELLED) {
                $this->assertReplacementCancellationSafe($exchange, $replacementRows);
            }
            $this->assertReplacementOrdering($exchange, $toStatus);
        } elseif ($dimension === StateDimension::SETTLEMENT) {
            if ($toStatus === SettlementStatus::CANCELLED) {
                $this->assertSettlementCancellationSafe($exchange, $settlementRows);
            }
            $this->assertSettlementOrdering($exchange, $toStatus, $returnRows);
        } elseif ($dimension === StateDimension::EXCHANGE
            && $toStatus === ExchangeStatus::IN_PROGRESS
        ) {
            $this->assertReturnIsAuthorized($exchange);
        } elseif ($dimension === StateDimension::EXCHANGE
            && $toStatus === ExchangeStatus::CANCELLED
        ) {
            $this->assertCancellationSafe($exchange, $returnRows, $replacementRows, $settlementRows);
        }
    }

    /**
     * Validate the workflow-only part of the mutex-protected cancellation.
     *
     * Native order, quote, document-link, and row-link proofs belong to the
     * specialized command that holds the original Magento order mutex.
     *
     * @param array<int, array<string, mixed>> $replacementRows
     * @throws InvariantViolationException
     */
    public function assertReplacementIntentCancellation(
        ExchangeInterface $exchange,
        array $replacementRows
    ): void {
        if ($exchange->getExchangeStatus() !== ExchangeStatus::IN_PROGRESS
            || !in_array(
                $exchange->getReturnStatus(),
                [ReturnStatus::ACCEPTED, ReturnStatus::PARTIALLY_ACCEPTED],
                true
            )
            || !in_array(
                $exchange->getReplacementStatus(),
                [ReplacementStatus::PENDING, ReplacementStatus::READY],
                true
            )
            || $exchange->getSettlementStatus() !== SettlementStatus::PENDING
        ) {
            throw new InvariantViolationException(
                __(
                    'Replacement cancellation requires an in-progress exchange, '
                    . 'an accepted return, a pending or ready replacement, and pending settlement.'
                )
            );
        }
        foreach ($replacementRows as $row) {
            if (($row[ReplacementItemInterface::REPLACEMENT_ORDER_ITEM_ID] ?? null) !== null) {
                throw new InvariantViolationException(
                    __('A linked replacement order item prevents replacement cancellation.')
                );
            }
        }
    }

    private function assertReturnOrdering(ExchangeInterface $exchange, string $toStatus): void
    {
        if ($toStatus === ReturnStatus::CANCELLED) {
            return;
        }
        if ($exchange->getReplacementStatus() === ReplacementStatus::CANCELLED
            || $exchange->getSettlementStatus() === SettlementStatus::CANCELLED
        ) {
            throw new InvariantViolationException(
                __('A return cannot advance after replacement or settlement cancellation.')
            );
        }
        if ($toStatus === ReturnStatus::AUTHORIZED
            && in_array(
                $exchange->getExchangeStatus(),
                [ExchangeStatus::APPROVED, ExchangeStatus::IN_PROGRESS],
                true
            )
        ) {
            return;
        }
        if (in_array(
            $toStatus,
            [
                ReturnStatus::IN_TRANSIT,
                ReturnStatus::RECEIVED,
                ReturnStatus::INSPECTED,
                ReturnStatus::ACCEPTED,
                ReturnStatus::PARTIALLY_ACCEPTED,
                ReturnStatus::REJECTED,
            ],
            true
        ) && $exchange->getExchangeStatus() === ExchangeStatus::IN_PROGRESS) {
            return;
        }
        if ($toStatus === ReturnStatus::PENDING) {
            return;
        }

        throw new InvariantViolationException(
            __('The exchange must be approved, then in progress, before the return can advance.')
        );
    }

    private function assertReplacementOrdering(ExchangeInterface $exchange, string $toStatus): void
    {
        if (in_array($toStatus, [ReplacementStatus::PENDING, ReplacementStatus::CANCELLED], true)) {
            return;
        }
        if ($exchange->getExchangeStatus() !== ExchangeStatus::IN_PROGRESS
            || !in_array(
                $exchange->getReturnStatus(),
                [ReturnStatus::ACCEPTED, ReturnStatus::PARTIALLY_ACCEPTED],
                true
            )
        ) {
            throw new InvariantViolationException(
                __('A replacement can advance only after the returned products are accepted.')
            );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $returnRows
     */
    private function assertSettlementOrdering(
        ExchangeInterface $exchange,
        string $toStatus,
        array $returnRows
    ): void {
        if ($exchange->getSettlementStatus() === SettlementStatus::PENDING
            && $toStatus !== SettlementStatus::PENDING
        ) {
            foreach ($returnRows as $row) {
                if ($this->quantityMath->compare(
                    (string)($row[ReturnItemInterface::ACCEPTED_QTY] ?? '0'),
                    (string)($row[ReturnItemInterface::CREDITED_QTY] ?? '0')
                ) !== 0) {
                    throw new InvariantViolationException(
                        __(
                            'Settlement cannot leave pending until every accepted '
                            . 'quantity has a linked native credit memo.'
                        )
                    );
                }
            }
        }
        if (in_array(
            $toStatus,
            [SettlementStatus::PENDING, SettlementStatus::FAILED, SettlementStatus::CANCELLED],
            true
        )) {
            return;
        }
        $balanceComparison = $this->moneyMath->compare(
            $exchange->getBalanceAmount(),
            '0'
        );
        if (($toStatus === SettlementStatus::PAYMENT_DUE && $balanceComparison <= 0)
            || ($toStatus === SettlementStatus::REFUND_DUE && $balanceComparison >= 0)
            || ($toStatus === SettlementStatus::BALANCED && $balanceComparison !== 0)
        ) {
            throw new InvariantViolationException(
                __('The settlement due status does not match the exchange balance direction.')
            );
        }
        if ($exchange->getExchangeStatus() !== ExchangeStatus::IN_PROGRESS
            || !in_array(
                $exchange->getReturnStatus(),
                [ReturnStatus::ACCEPTED, ReturnStatus::PARTIALLY_ACCEPTED],
                true
            )
            || $exchange->getReplacementStatus() === ReplacementStatus::PENDING
        ) {
            throw new InvariantViolationException(
                __('Settlement can advance only after return acceptance and replacement readiness.')
            );
        }
        if ($exchange->getReplacementStatus() === ReplacementStatus::CANCELLED
            && !in_array(
                $toStatus,
                [
                    SettlementStatus::REFUND_DUE,
                    SettlementStatus::REFUND_ISSUED,
                    SettlementStatus::BALANCED,
                ],
                true
            )
        ) {
            throw new InvariantViolationException(
                __('A cancelled replacement can only proceed through refund-only settlement.')
            );
        }
    }

    private function assertReturnIsAuthorized(ExchangeInterface $exchange): void
    {
        if (!in_array(
            $exchange->getReturnStatus(),
            [
                ReturnStatus::AUTHORIZED,
                ReturnStatus::IN_TRANSIT,
                ReturnStatus::RECEIVED,
                ReturnStatus::INSPECTED,
                ReturnStatus::ACCEPTED,
                ReturnStatus::PARTIALLY_ACCEPTED,
                ReturnStatus::REJECTED,
            ],
            true
        )) {
            throw new InvariantViolationException(
                __('An exchange can start only after the return is authorized.')
            );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $returnRows
     * @param array<int, array<string, mixed>> $replacementRows
     * @param array<int, array<string, mixed>> $settlementRows
     */
    private function assertCancellationSafe(
        ExchangeInterface $exchange,
        array $returnRows,
        array $replacementRows,
        array $settlementRows
    ): void {
        if (!in_array(
            $exchange->getReturnStatus(),
            [
                ReturnStatus::PENDING,
                ReturnStatus::AUTHORIZED,
                ReturnStatus::IN_TRANSIT,
                ReturnStatus::CANCELLED,
            ],
            true
        ) || !in_array(
            $exchange->getReplacementStatus(),
            [
                ReplacementStatus::PENDING,
                ReplacementStatus::CANCELLED,
            ],
            true
        ) || !in_array(
            $exchange->getSettlementStatus(),
            [
                SettlementStatus::PENDING,
                SettlementStatus::PAYMENT_DUE,
                SettlementStatus::REFUND_DUE,
                SettlementStatus::FAILED,
                SettlementStatus::CANCELLED,
            ],
            true
        )) {
            throw new InvariantViolationException(
                __('The exchange has workflow side effects that prevent cancellation.')
            );
        }

        $this->assertReturnCancellationSafe($exchange, $returnRows);
        $this->assertReplacementCancellationSafe($exchange, $replacementRows);
        $this->assertSettlementCancellationSafe($exchange, $settlementRows);
    }

    /**
     * @param array<int, array<string, mixed>> $returnRows
     */
    private function assertReturnCancellationSafe(
        ExchangeInterface $exchange,
        array $returnRows
    ): void {
        if (!in_array(
            $exchange->getReturnStatus(),
            [
                ReturnStatus::PENDING,
                ReturnStatus::AUTHORIZED,
                ReturnStatus::IN_TRANSIT,
                ReturnStatus::CANCELLED,
            ],
            true
        )) {
            throw new InvariantViolationException(
                __('The return has physical side effects that prevent cancellation.')
            );
        }
        foreach ($returnRows as $row) {
            foreach ([
                ReturnItemInterface::RECEIVED_QTY,
                ReturnItemInterface::ACCEPTED_QTY,
                ReturnItemInterface::REJECTED_QTY,
            ] as $field) {
                if ($this->quantityMath->compare((string)($row[$field] ?? '0'), '0') !== 0) {
                    throw new InvariantViolationException(
                        __('A received or inspected return prevents exchange cancellation.')
                    );
                }
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $replacementRows
     */
    private function assertReplacementCancellationSafe(
        ExchangeInterface $exchange,
        array $replacementRows
    ): void {
        if (!in_array(
            $exchange->getReplacementStatus(),
            [
                ReplacementStatus::PENDING,
                ReplacementStatus::CANCELLED,
            ],
            true
        )) {
            throw new InvariantViolationException(
                __('The replacement has order side effects that prevent cancellation.')
            );
        }
        if (!in_array(
            $exchange->getSettlementStatus(),
            [SettlementStatus::PENDING, SettlementStatus::CANCELLED],
            true
        )) {
            throw new InvariantViolationException(
                __('Replacement cancellation is unsafe after settlement has advanced.')
            );
        }
        foreach ($replacementRows as $row) {
            if (!empty($row[ReplacementItemInterface::REPLACEMENT_ORDER_ITEM_ID])) {
                throw new InvariantViolationException(
                    __('A linked replacement order prevents exchange cancellation.')
                );
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $settlementRows
     */
    private function assertSettlementCancellationSafe(
        ExchangeInterface $exchange,
        array $settlementRows
    ): void {
        if (!in_array(
            $exchange->getSettlementStatus(),
            [
                SettlementStatus::PENDING,
                SettlementStatus::PAYMENT_DUE,
                SettlementStatus::REFUND_DUE,
                SettlementStatus::FAILED,
                SettlementStatus::CANCELLED,
            ],
            true
        )) {
            throw new InvariantViolationException(
                __('The settlement has financial side effects that prevent cancellation.')
            );
        }
        if (!in_array(
            $exchange->getReturnStatus(),
            [
                ReturnStatus::PENDING,
                ReturnStatus::AUTHORIZED,
                ReturnStatus::IN_TRANSIT,
                ReturnStatus::REJECTED,
                ReturnStatus::CANCELLED,
            ],
            true
        )) {
            throw new InvariantViolationException(
                __('An accepted or received return requires settlement to be reconciled, not cancelled.')
            );
        }
        foreach ($settlementRows as $row) {
            $entryStatus = (string)($row[SettlementInterface::STATUS] ?? '');
            if ($entryStatus !== EntryStatus::CANCELLED) {
                throw new InvariantViolationException(
                    __('Cancel every eligible ledger entry before cancelling settlement.')
                );
            }
        }
    }
}
