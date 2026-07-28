<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model;

use Bonlineco\SalesExchange\Api\BalanceCalculatorInterface;
use Bonlineco\SalesExchange\Api\Data\ExchangeInterface;
use Bonlineco\SalesExchange\Api\Data\ReplacementItemInterface;
use Bonlineco\SalesExchange\Api\Data\ReturnItemInterface;
use Bonlineco\SalesExchange\Api\Data\SettlementInterface;
use Bonlineco\SalesExchange\Api\Settlement\EntryStatus;
use Bonlineco\SalesExchange\Api\Settlement\Type;
use Bonlineco\SalesExchange\Api\Status\ReplacementStatus;
use Bonlineco\SalesExchange\Api\Status\ReturnStatus;
use Bonlineco\SalesExchange\Api\Status\SettlementStatus;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Creditmemo\ReturnCreditProjection;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;

/**
 * Validate successful workflow closure against persisted line and ledger data.
 */
class CompletionValidator
{
    private DecimalMath $moneyMath;

    private DecimalMath $quantityMath;

    private FinancialAggregateCalculator $aggregateCalculator;

    private BalanceCalculatorInterface $balanceCalculator;

    private ReturnCreditProjection $returnCreditProjection;

    private NativeReplacementProjection $nativeReplacementProjection;

    public function __construct(
        DecimalMath $moneyMath,
        DecimalMath $quantityMath,
        FinancialAggregateCalculator $aggregateCalculator,
        BalanceCalculatorInterface $balanceCalculator,
        ReturnCreditProjection $returnCreditProjection,
        NativeReplacementProjection $nativeReplacementProjection
    ) {
        $this->moneyMath = $moneyMath;
        $this->quantityMath = $quantityMath;
        $this->aggregateCalculator = $aggregateCalculator;
        $this->balanceCalculator = $balanceCalculator;
        $this->returnCreditProjection = $returnCreditProjection;
        $this->nativeReplacementProjection = $nativeReplacementProjection;
    }

    /**
     * @param array<int, array<string, mixed>> $returnRows
     * @param array<int, array<string, mixed>> $replacementRows
     * @param array<int, array<string, mixed>> $settlementRows
     * @throws InvariantViolationException
     */
    public function execute(
        ExchangeInterface $exchange,
        array $returnRows,
        array $replacementRows,
        array $settlementRows
    ): void {
        if (!in_array(
            $exchange->getReturnStatus(),
            [ReturnStatus::ACCEPTED, ReturnStatus::PARTIALLY_ACCEPTED],
            true
        )) {
            throw new InvariantViolationException(
                __('A completed exchange requires an accepted or partially accepted return.')
            );
        }
        $this->assertReturnState($exchange->getReturnStatus(), $returnRows);
        $this->returnCreditProjection->assertFullyCredited($returnRows);

        if (!in_array(
            $exchange->getReplacementStatus(),
            [ReplacementStatus::DELIVERED, ReplacementStatus::CANCELLED],
            true
        )) {
            throw new InvariantViolationException(
                __('A completed exchange requires a delivered replacement or a cancelled replacement intent.')
            );
        }
        if ($exchange->getReplacementStatus() === ReplacementStatus::DELIVERED) {
            $this->assertReplacementState($exchange, $replacementRows);
        } else {
            $this->assertCancelledReplacementState($exchange, $replacementRows);
        }
        $this->assertFinancialTotals($exchange, $returnRows, $replacementRows);
        if ($exchange->getSettlementStatus() === SettlementStatus::CANCELLED) {
            throw new InvariantViolationException(
                __('A completed exchange requires a successfully reconciled settlement.')
            );
        }
        $this->assertSettlementState($exchange, $settlementRows, $exchange->getSettlementStatus());
        if ($exchange->getReplacementStatus() === ReplacementStatus::CANCELLED) {
            $this->assertRefundOnlySettlement($settlementRows);
        }
    }

    /**
     * Validate the explicit terminal outcome for a fully rejected return.
     *
     * @param array<int, array<string, mixed>> $returnRows
     * @param array<int, array<string, mixed>> $replacementRows
     * @param array<int, array<string, mixed>> $settlementRows
     */
    public function assertRejectedOutcome(
        ExchangeInterface $exchange,
        array $returnRows,
        array $replacementRows,
        array $settlementRows
    ): void {
        if ($exchange->getReturnStatus() !== ReturnStatus::REJECTED) {
            throw new InvariantViolationException(
                __('A rejected exchange requires a fully rejected return.')
            );
        }
        $this->assertReturnState(ReturnStatus::REJECTED, $returnRows);
        if ($this->moneyMath->compare(
            $this->aggregateCalculator->getReturnCredit($returnRows),
            '0'
        ) !== 0 || $this->moneyMath->compare($exchange->getReturnCreditAmount(), '0') !== 0) {
            throw new InvariantViolationException(
                __('A rejected exchange cannot retain return credit.')
            );
        }
        if ($this->moneyMath->compare($exchange->getNativeReturnCreditAmount(), '0') !== 0
            || $this->moneyMath->compare($exchange->getBaseNativeReturnCreditAmount(), '0') !== 0
        ) {
            throw new InvariantViolationException(
                __('A rejected exchange cannot retain a native return credit.')
            );
        }
        if ($exchange->getReplacementStatus() !== ReplacementStatus::CANCELLED) {
            throw new InvariantViolationException(
                __('A rejected exchange requires its replacement intent to be cancelled.')
            );
        }
        $this->assertCancelledReplacementState($exchange, $replacementRows);
        if ($exchange->getSettlementStatus() !== SettlementStatus::CANCELLED) {
            throw new InvariantViolationException(
                __('A rejected exchange requires its settlement workflow to be cancelled.')
            );
        }
        foreach ($settlementRows as $row) {
            if ((string)($row[SettlementInterface::STATUS] ?? '') !== EntryStatus::CANCELLED) {
                throw new InvariantViolationException(
                    __('Every rejected-exchange ledger entry must be cancelled.')
                );
            }
        }
        if ($this->moneyMath->compare($exchange->getBalanceAmount(), '0') !== 0) {
            throw new InvariantViolationException(
                __('A rejected exchange must have a zero balance.')
            );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function assertReturnAuthorization(array $rows): void
    {
        if ($rows === []) {
            throw new InvariantViolationException(
                __('A return cannot be authorized without return items.')
            );
        }
        foreach ($rows as $row) {
            $requested = $this->quantityMath->normalize(
                (string)$row[ReturnItemInterface::REQUESTED_QTY]
            );
            $allocated = $this->quantityMath->normalize(
                (string)$row[ReturnItemInterface::ALLOCATED_QTY]
            );
            if ($this->quantityMath->compare($requested, '0') <= 0
                || $this->quantityMath->compare($allocated, $requested) !== 0
            ) {
                throw new InvariantViolationException(
                    __('Authorization requires every requested quantity to be positively allocated in full.')
                );
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function assertReturnReceipt(array $rows): void
    {
        if ($rows === []) {
            throw new InvariantViolationException(
                __('A return cannot be received without return items.')
            );
        }
        $received = '0.0000';
        foreach ($rows as $row) {
            if ((int)($row[ReturnItemInterface::RECEIPT_RESOLVED] ?? 0) !== 1) {
                throw new InvariantViolationException(
                    __('Every authorized return line must have an explicit warehouse receipt result.')
                );
            }
            $rowReceived = $this->quantityMath->normalize(
                (string)$row[ReturnItemInterface::RECEIVED_QTY]
            );
            $allocated = $this->quantityMath->normalize(
                (string)$row[ReturnItemInterface::ALLOCATED_QTY]
            );
            if ($this->quantityMath->compare($rowReceived, $allocated) > 0) {
                throw new InvariantViolationException(
                    __('Received quantity cannot exceed authorized allocation.')
                );
            }
            $received = $this->quantityMath->add($received, $rowReceived);
        }
        if ($this->quantityMath->compare($received, '0') <= 0) {
            throw new InvariantViolationException(
                __('Receipt requires at least one received unit.')
            );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @throws InvariantViolationException
     */
    public function assertReturnState(string $status, array $rows): void
    {
        if ($status === ReturnStatus::CANCELLED) {
            return;
        }
        if ($rows === []) {
            throw new InvariantViolationException(__('A return cannot close without return items.'));
        }

        $received = '0.0000';
        $accepted = '0.0000';
        $rejected = '0.0000';
        foreach ($rows as $row) {
            if ((int)($row[ReturnItemInterface::RECEIPT_RESOLVED] ?? 0) !== 1) {
                throw new InvariantViolationException(
                    __('Every return line must have a resolved warehouse receipt.')
                );
            }
            $rowReceived = $this->quantityMath->normalize(
                (string)$row[ReturnItemInterface::RECEIVED_QTY]
            );
            $rowAccepted = $this->quantityMath->normalize(
                (string)$row[ReturnItemInterface::ACCEPTED_QTY]
            );
            $rowRejected = $this->quantityMath->normalize(
                (string)$row[ReturnItemInterface::REJECTED_QTY]
            );
            if ($this->quantityMath->compare(
                $this->quantityMath->add($rowAccepted, $rowRejected),
                $rowReceived
            ) !== 0) {
                throw new InvariantViolationException(
                    __('Every received unit must have an accepted or rejected inspection outcome.')
                );
            }
            $received = $this->quantityMath->add($received, $rowReceived);
            $accepted = $this->quantityMath->add($accepted, $rowAccepted);
            $rejected = $this->quantityMath->add($rejected, $rowRejected);
        }

        if ($this->quantityMath->compare($received, '0') <= 0) {
            throw new InvariantViolationException(__('A closed return must contain received units.'));
        }
        if ($status === ReturnStatus::ACCEPTED
            && ($this->quantityMath->compare($accepted, '0') <= 0
                || $this->quantityMath->compare($rejected, '0') !== 0)
        ) {
            throw new InvariantViolationException(
                __('An accepted return must accept every received unit.')
            );
        }
        if ($status === ReturnStatus::PARTIALLY_ACCEPTED
            && ($this->quantityMath->compare($accepted, '0') <= 0
                || $this->quantityMath->compare($rejected, '0') <= 0)
        ) {
            throw new InvariantViolationException(
                __('A partially accepted return requires both accepted and rejected units.')
            );
        }
        if ($status === ReturnStatus::REJECTED
            && ($this->quantityMath->compare($accepted, '0') !== 0
                || $this->quantityMath->compare($rejected, '0') <= 0)
        ) {
            throw new InvariantViolationException(
                __('A rejected return cannot contain accepted units.')
            );
        }
        $this->aggregateCalculator->getReturnCredit($rows);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @throws InvariantViolationException
     */
    public function assertReplacementState(ExchangeInterface $exchange, array $rows): void
    {
        if ($rows === []) {
            throw new InvariantViolationException(
                __('A delivered replacement requires replacement items.')
            );
        }

        foreach ($rows as $row) {
            if (empty($row[ReplacementItemInterface::REPLACEMENT_ORDER_ITEM_ID])) {
                throw new InvariantViolationException(
                    __('Every delivered replacement item must be linked to a Magento order item.')
                );
            }
        }
        $this->assertReplacementTotal($exchange, $rows);
    }

    /**
     * Validate and derive the merchandise total at the PENDING -> READY freeze.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public function getReadyReplacementAmount(array $rows): string
    {
        return $this->aggregateCalculator->getReplacementAmount($rows);
    }

    /**
     * Derive the approved credit at the terminal return freeze.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public function getApprovedReturnCredit(array $rows): string
    {
        return $this->aggregateCalculator->getReturnCredit($rows);
    }

    /**
     * Rebuild parent return, replacement, and balance totals from persisted rows.
     *
     * @param array<int, array<string, mixed>> $returnRows
     * @param array<int, array<string, mixed>> $replacementRows
     */
    public function assertFinancialTotals(
        ExchangeInterface $exchange,
        array $returnRows,
        array $replacementRows
    ): void {
        $returnTotal = $this->assertReturnCredit($exchange, $returnRows);
        $this->returnCreditProjection->assertFullyCredited($returnRows);
        if ($exchange->getReplacementStatus() === ReplacementStatus::CANCELLED) {
            $this->assertCancelledReplacementState($exchange, $replacementRows);
        } else {
            $this->assertReplacementTotal($exchange, $replacementRows);
        }
        $replacementCharge = $this->nativeReplacementProjection->execute(
            $exchange->getReplacementStatus(),
            $exchange->getReplacementAmount(),
            $exchange->getShippingAmount(),
            $exchange->getNativeReplacementAmount()
        );
        $derivedBalance = $this->balanceCalculator->execute(
            $replacementCharge,
            '0.0000',
            $exchange->getFeeAmount(),
            $returnTotal
        );
        if ($this->moneyMath->compare($derivedBalance, $exchange->getBalanceAmount()) !== 0) {
            throw new InvariantViolationException(
                __('The exchange balance does not match its persisted line totals.')
            );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @throws InvariantViolationException
     */
    public function assertSettlementState(
        ExchangeInterface $exchange,
        array $rows,
        string $status
    ): void {
        if ($status === SettlementStatus::CANCELLED) {
            return;
        }

        $balance = $this->moneyMath->normalize($exchange->getBalanceAmount());
        $comparison = $this->moneyMath->compare($balance, '0');
        $expectedStatus = SettlementStatus::BALANCED;
        if ($comparison > 0) {
            $expectedStatus = SettlementStatus::PAYMENT_RECEIVED;
        } elseif ($comparison < 0) {
            $expectedStatus = SettlementStatus::REFUND_ISSUED;
        }
        if ($status !== $expectedStatus) {
            throw new InvariantViolationException(
                __('Settlement status "%1" does not match the exchange balance.', $status)
            );
        }

        $cashTotal = '0.0000';
        $creditTotal = '0.0000';
        foreach ($rows as $row) {
            $entryStatus = (string)$row[SettlementInterface::STATUS];
            if (in_array($entryStatus, [EntryStatus::PENDING, EntryStatus::PROCESSING], true)) {
                throw new InvariantViolationException(
                    __('Settlement cannot close while ledger entries are pending or processing.')
                );
            }
            if ($entryStatus !== EntryStatus::SUCCEEDED) {
                continue;
            }
            if ((string)$row[SettlementInterface::CURRENCY_CODE] !== $exchange->getCurrencyCode()) {
                throw new InvariantViolationException(
                    __('Settlement ledger currency does not match the exchange currency.')
                );
            }
            if ((string)$row[SettlementInterface::TYPE] === Type::RETURN_CREDIT) {
                $creditTotal = $this->moneyMath->add(
                    $creditTotal,
                    (string)$row[SettlementInterface::AMOUNT]
                );
                continue;
            }
            if (in_array(
                (string)$row[SettlementInterface::TYPE],
                [Type::CUSTOMER_PAYMENT, Type::MERCHANT_REFUND],
                true
            ) && trim(
                (string)($row[SettlementInterface::EXTERNAL_REFERENCE] ?? '')
            ) === '') {
                throw new InvariantViolationException(
                    __('Successful external cash settlements require an external reference.')
                );
            }
            $cashTotal = $this->moneyMath->add(
                $cashTotal,
                (string)$row[SettlementInterface::AMOUNT]
            );
        }

        if ($this->moneyMath->compare(
            $creditTotal,
            $exchange->getNativeReturnCreditAmount()
        ) !== 0) {
            throw new InvariantViolationException(
                __('Successful return-credit entries do not match the executed native return credit.')
            );
        }
        if ($this->moneyMath->compare($cashTotal, $balance) !== 0) {
            throw new InvariantViolationException(
                __('Successful payment, refund, and adjustment entries do not reconcile the balance.')
            );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function assertReturnCredit(ExchangeInterface $exchange, array $rows): string
    {
        $approved = $this->aggregateCalculator->getReturnCredit($rows);
        if ($this->moneyMath->compare($approved, $exchange->getReturnCreditAmount()) !== 0) {
            throw new InvariantViolationException(
                __('Return item credits do not match the exchange approved return credit.')
            );
        }

        return $this->returnCreditProjection->execute(
            $exchange->getNativeReturnCreditAmount(),
            $rows
        );
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function assertReplacementTotal(ExchangeInterface $exchange, array $rows): string
    {
        $total = $this->aggregateCalculator->getReplacementAmount($rows);
        if ($this->moneyMath->compare($total, $exchange->getReplacementAmount()) !== 0) {
            throw new InvariantViolationException(
                __('Replacement item totals do not match the exchange replacement amount.')
            );
        }

        return $total;
    }

    /**
     * Cancelled replacement rows remain audit snapshots but have no financial effect.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function assertCancelledReplacementState(
        ExchangeInterface $exchange,
        array $rows
    ): void {
        if ($this->moneyMath->compare($exchange->getFeeAmount(), '0') !== 0) {
            throw new InvariantViolationException(
                __('A cancelled replacement cannot retain an exchange fee.')
            );
        }
        if ($this->moneyMath->compare(
            $exchange->getNativeReplacementAmount(),
            '0'
        ) !== 0 || $this->moneyMath->compare(
            $exchange->getBaseNativeReplacementAmount(),
            '0'
        ) !== 0) {
            throw new InvariantViolationException(
                __('A cancelled replacement cannot retain native replacement order totals.')
            );
        }
        foreach ($rows as $row) {
            if (($row[ReplacementItemInterface::REPLACEMENT_ORDER_ITEM_ID] ?? null) !== null) {
                throw new InvariantViolationException(
                    __('A cancelled replacement cannot retain a Magento order item link.')
                );
            }
        }
        if ($rows === []) {
            if ($this->moneyMath->compare($exchange->getReplacementAmount(), '0') !== 0) {
                throw new InvariantViolationException(
                    __('A cancelled replacement without item snapshots cannot retain an approved amount.')
                );
            }

            return;
        }

        $approved = $this->aggregateCalculator->getReplacementAmount($rows);
        if ($this->moneyMath->compare($exchange->getReplacementAmount(), '0') !== 0
            && $this->moneyMath->compare(
                $exchange->getReplacementAmount(),
                $approved
            ) !== 0
        ) {
            throw new InvariantViolationException(
                __('A cancelled replacement must retain either no freeze or its full approved item total.')
            );
        }
    }

    /**
     * Refund-only completion cannot hide a customer charge or manual adjustment.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function assertRefundOnlySettlement(array $rows): void
    {
        foreach ($rows as $row) {
            if ((string)($row[SettlementInterface::STATUS] ?? '') !== EntryStatus::SUCCEEDED) {
                continue;
            }
            $type = (string)($row[SettlementInterface::TYPE] ?? '');
            if (!in_array($type, [Type::RETURN_CREDIT, Type::MERCHANT_REFUND], true)) {
                throw new InvariantViolationException(
                    __('Refund-only completion cannot contain a successful customer charge or adjustment.')
                );
            }
        }
    }
}
