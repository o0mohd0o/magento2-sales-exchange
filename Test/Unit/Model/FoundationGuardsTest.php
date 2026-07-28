<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Unit\Model;

use Bonlineco\SalesExchange\Api\Data\ExchangeInterface;
use Bonlineco\SalesExchange\Api\Data\ReturnItemInterface;
use Bonlineco\SalesExchange\Api\Status\ExchangeStatus;
use Bonlineco\SalesExchange\Api\Status\ReplacementStatus;
use Bonlineco\SalesExchange\Api\Status\ReturnStatus;
use Bonlineco\SalesExchange\Api\Status\SettlementStatus;
use Bonlineco\SalesExchange\Api\Status\StateDimension;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\BalanceCalculator;
use Bonlineco\SalesExchange\Model\CompletionValidator;
use Bonlineco\SalesExchange\Model\Creditmemo\ReturnCreditProjection;
use Bonlineco\SalesExchange\Model\FinancialAggregateCalculator;
use Bonlineco\SalesExchange\Model\FinancialRowCalculator;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Bonlineco\SalesExchange\Model\NativeReplacementProjection;
use Bonlineco\SalesExchange\Model\ReplacementCurrencyCalculator;
use Bonlineco\SalesExchange\Model\ReturnItemLifecycleValidator;
use Bonlineco\SalesExchange\Model\VersionGuard;
use Bonlineco\SalesExchange\Model\WorkflowCoordinator;
use PHPUnit\Framework\TestCase;

/**
 * Focused coverage for Phase 1 coordination, math, lifecycle, and stale-write guards.
 */
class FoundationGuardsTest extends TestCase
{
    public function testVersionGuardIncrementsCurrentVersion(): void
    {
        self::assertSame(
            8,
            (new VersionGuard())->assertCurrentAndIncrement(7, 7, 'exchange case')
        );
    }

    public function testVersionGuardRejectsStaleWrite(): void
    {
        $this->expectException(InvariantViolationException::class);
        (new VersionGuard())->assertCurrentAndIncrement(6, 7, 'exchange case');
    }

    public function testFinancialRowsUseFourDecimalHalfUpRounding(): void
    {
        $calculator = new FinancialRowCalculator(
            new DecimalMath(),
            new DecimalMath(4, 12)
        );

        self::assertSame('0.0001', $calculator->execute('0.0001', '0.5000'));
        self::assertSame('0.1111', $calculator->execute('0.3333', '0.3333'));
    }

    public function testAuthorizationRequiresPositiveFullAllocation(): void
    {
        $validator = $this->createCompletionValidator();
        $this->expectException(InvariantViolationException::class);

        $validator->assertReturnAuthorization([[
            ReturnItemInterface::REQUESTED_QTY => '2.0000',
            ReturnItemInterface::ALLOCATED_QTY => '1.0000',
        ]]);
    }

    public function testAuthorizedReturnFreezesRequestedQuantity(): void
    {
        $item = $this->createMock(ReturnItemInterface::class);
        $item->method('getRequestedQty')->willReturn('1.0000');
        $item->method('getAllocatedQty')->willReturn('2.0000');
        $item->method('getReceivedQty')->willReturn('0.0000');
        $item->method('getAcceptedQty')->willReturn('0.0000');
        $item->method('getRejectedQty')->willReturn('0.0000');
        $item->method('getUnitCreditAmount')->willReturn('100.0000');
        $item->method('getRowCreditAmount')->willReturn('0.0000');
        $item->method('getSku')->willReturn('original-sku');
        $item->method('getName')->willReturn('Original item');
        $persisted = [
            ReturnItemInterface::SKU => 'original-sku',
            ReturnItemInterface::NAME => 'Original item',
            ReturnItemInterface::REQUESTED_QTY => '2.0000',
            ReturnItemInterface::ALLOCATED_QTY => '2.0000',
            ReturnItemInterface::RECEIVED_QTY => '0.0000',
            ReturnItemInterface::ACCEPTED_QTY => '0.0000',
            ReturnItemInterface::REJECTED_QTY => '0.0000',
            ReturnItemInterface::UNIT_CREDIT_AMOUNT => '100.0000',
            ReturnItemInterface::ROW_CREDIT_AMOUNT => '0.0000',
        ];
        $this->expectException(InvariantViolationException::class);

        (new ReturnItemLifecycleValidator(
            new DecimalMath(),
            new DecimalMath(4, 12)
        ))->execute($item, $persisted, ReturnStatus::AUTHORIZED);
    }

    public function testPendingReturnCannotChangeOrderDerivedUnitCredit(): void
    {
        $item = $this->createMock(ReturnItemInterface::class);
        $item->method('getSku')->willReturn('original-sku');
        $item->method('getName')->willReturn('Original item');
        $item->method('getUnitCreditAmount')->willReturn('999.0000');
        $this->expectException(InvariantViolationException::class);

        (new ReturnItemLifecycleValidator(
            new DecimalMath(),
            new DecimalMath(4, 12)
        ))->execute(
            $item,
            [
                ReturnItemInterface::SKU => 'original-sku',
                ReturnItemInterface::NAME => 'Original item',
                ReturnItemInterface::UNIT_CREDIT_AMOUNT => '100.0000',
            ],
            ReturnStatus::PENDING
        );
    }

    public function testClosedCaseRejectsSubordinateTransition(): void
    {
        $exchange = $this->exchange(
            ExchangeStatus::COMPLETED,
            ReturnStatus::ACCEPTED,
            ReplacementStatus::DELIVERED,
            SettlementStatus::BALANCED
        );
        $this->expectException(InvariantViolationException::class);

        $this->coordinator()->execute(
            $exchange,
            StateDimension::RETURN,
            ReturnStatus::ACCEPTED,
            [],
            [],
            []
        );
    }

    public function testReplacementCannotAdvanceBeforeReturnAcceptance(): void
    {
        $exchange = $this->exchange(
            ExchangeStatus::IN_PROGRESS,
            ReturnStatus::RECEIVED,
            ReplacementStatus::PENDING,
            SettlementStatus::PENDING
        );
        $this->expectException(InvariantViolationException::class);

        $this->coordinator()->execute(
            $exchange,
            StateDimension::REPLACEMENT,
            ReplacementStatus::READY,
            [],
            [],
            []
        );
    }

    public function testOverallCancellationRejectsReceivedUnits(): void
    {
        $exchange = $this->exchange(
            ExchangeStatus::IN_PROGRESS,
            ReturnStatus::AUTHORIZED,
            ReplacementStatus::PENDING,
            SettlementStatus::PENDING
        );
        $this->expectException(InvariantViolationException::class);

        $this->coordinator()->execute(
            $exchange,
            StateDimension::EXCHANGE,
            ExchangeStatus::CANCELLED,
            [[ReturnItemInterface::RECEIVED_QTY => '1.0000']],
            [],
            []
        );
    }

    public function testSettlementCannotLeavePendingBeforeNativeCreditHandoff(): void
    {
        $exchange = $this->exchange(
            ExchangeStatus::IN_PROGRESS,
            ReturnStatus::ACCEPTED,
            ReplacementStatus::READY,
            SettlementStatus::PENDING
        );
        $exchange->method('getBalanceAmount')->willReturn('10.0000');
        $this->expectException(InvariantViolationException::class);

        $this->coordinator()->execute(
            $exchange,
            StateDimension::SETTLEMENT,
            SettlementStatus::PAYMENT_DUE,
            [[
                ReturnItemInterface::ACCEPTED_QTY => '1.0000',
                ReturnItemInterface::CREDITED_QTY => '0.0000',
            ]],
            [],
            []
        );
    }

    public function testSettlementDueDirectionMustMatchProjectedBalance(): void
    {
        $exchange = $this->exchange(
            ExchangeStatus::IN_PROGRESS,
            ReturnStatus::ACCEPTED,
            ReplacementStatus::READY,
            SettlementStatus::PENDING
        );
        $exchange->method('getBalanceAmount')->willReturn('-10.0000');
        $this->expectException(InvariantViolationException::class);

        $this->coordinator()->execute(
            $exchange,
            StateDimension::SETTLEMENT,
            SettlementStatus::PAYMENT_DUE,
            [[
                ReturnItemInterface::ACCEPTED_QTY => '1.0000',
                ReturnItemInterface::CREDITED_QTY => '1.0000',
            ]],
            [],
            []
        );
    }

    public function testBalancedSettlementRequiresExactlyZeroProjectedBalance(): void
    {
        $exchange = $this->exchange(
            ExchangeStatus::IN_PROGRESS,
            ReturnStatus::ACCEPTED,
            ReplacementStatus::READY,
            SettlementStatus::PENDING
        );
        $exchange->method('getBalanceAmount')->willReturn('0.0001');
        $this->expectException(InvariantViolationException::class);

        $this->coordinator()->execute(
            $exchange,
            StateDimension::SETTLEMENT,
            SettlementStatus::BALANCED,
            [[
                ReturnItemInterface::ACCEPTED_QTY => '1.0000',
                ReturnItemInterface::CREDITED_QTY => '1.0000',
            ]],
            [],
            []
        );
    }

    private function createCompletionValidator(): CompletionValidator
    {
        $moneyMath = new DecimalMath();
        $quantityMath = new DecimalMath(4, 12);

        $rowCalculator = new FinancialRowCalculator($moneyMath, $quantityMath);
        return new CompletionValidator(
            $moneyMath,
            $quantityMath,
            new FinancialAggregateCalculator(
                $moneyMath,
                $quantityMath,
                $rowCalculator,
                new ReplacementCurrencyCalculator($moneyMath, $quantityMath)
            ),
            new BalanceCalculator($moneyMath),
            new ReturnCreditProjection($moneyMath, $quantityMath, $rowCalculator),
            new NativeReplacementProjection($moneyMath)
        );
    }

    private function coordinator(): WorkflowCoordinator
    {
        return new WorkflowCoordinator(new DecimalMath(), new DecimalMath(4, 12));
    }

    private function exchange(
        string $exchangeStatus,
        string $returnStatus,
        string $replacementStatus,
        string $settlementStatus
    ): ExchangeInterface {
        $exchange = $this->createMock(ExchangeInterface::class);
        $exchange->method('getExchangeStatus')->willReturn($exchangeStatus);
        $exchange->method('getReturnStatus')->willReturn($returnStatus);
        $exchange->method('getReplacementStatus')->willReturn($replacementStatus);
        $exchange->method('getSettlementStatus')->willReturn($settlementStatus);

        return $exchange;
    }
}
