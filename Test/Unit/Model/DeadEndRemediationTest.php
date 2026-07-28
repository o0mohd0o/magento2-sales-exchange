<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Unit\Model;

use Bonlineco\SalesExchange\Api\Data\ExchangeInterface;
use Bonlineco\SalesExchange\Api\Data\ReplacementItemInterface;
use Bonlineco\SalesExchange\Api\Data\ReturnItemInterface;
use Bonlineco\SalesExchange\Api\Data\SettlementInterface;
use Bonlineco\SalesExchange\Api\Settlement\EntryStatus;
use Bonlineco\SalesExchange\Api\Settlement\Type;
use Bonlineco\SalesExchange\Api\Status\ExchangeStatus;
use Bonlineco\SalesExchange\Api\Status\ReplacementStatus;
use Bonlineco\SalesExchange\Api\Status\ReturnStatus;
use Bonlineco\SalesExchange\Api\Status\SettlementStatus;
use Bonlineco\SalesExchange\Api\Status\StateDimension;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\AggregateVersionBumper;
use Bonlineco\SalesExchange\Model\BalanceCalculator;
use Bonlineco\SalesExchange\Model\CompletionValidator;
use Bonlineco\SalesExchange\Model\Creditmemo\ReturnCreditProjection;
use Bonlineco\SalesExchange\Model\FinancialAggregateCalculator;
use Bonlineco\SalesExchange\Model\FinancialRowCalculator;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Bonlineco\SalesExchange\Model\NativeReplacementProjection;
use Bonlineco\SalesExchange\Model\ReplacementCurrencyCalculator;
use Bonlineco\SalesExchange\Model\ResourceModel\Exchange as ExchangeResource;
use Bonlineco\SalesExchange\Model\SettlementIntentValidator;
use Bonlineco\SalesExchange\Model\StateTransitionGuard;
use Bonlineco\SalesExchange\Model\VersionGuard;
use Bonlineco\SalesExchange\Model\WorkflowCoordinator;
use PHPUnit\Framework\TestCase;

/**
 * Focused coverage for the terminal workflow remediation rules.
 */
class DeadEndRemediationTest extends TestCase
{
    public function testRejectedExchangeIsAnExplicitTerminalOutcome(): void
    {
        $guard = new StateTransitionGuard();
        $guard->execute(
            StateDimension::EXCHANGE,
            ExchangeStatus::IN_PROGRESS,
            ExchangeStatus::REJECTED
        );

        self::assertTrue(
            in_array(ExchangeStatus::REJECTED, ExchangeStatus::terminal(), true)
        );
    }

    public function testReadyFreezeRejectsAnEmptyReplacementSelection(): void
    {
        $this->expectException(InvariantViolationException::class);
        $this->completionValidator()->getReadyReplacementAmount([]);
    }

    public function testFreezeCalculatorsDerivePersistedRowAggregates(): void
    {
        $validator = $this->completionValidator();
        self::assertSame(
            '75.0000',
            $validator->getApprovedReturnCredit([[
                ReturnItemInterface::ACCEPTED_QTY => '1.5000',
                ReturnItemInterface::UNIT_CREDIT_AMOUNT => '50.0000',
                ReturnItemInterface::ROW_CREDIT_AMOUNT => '75.0000',
            ]])
        );
        self::assertSame(
            '125.0000',
            $validator->getReadyReplacementAmount([[
                ReplacementItemInterface::SKU => 'new-sku',
                ReplacementItemInterface::NAME => 'New product',
                ReplacementItemInterface::QTY => '2.0000',
                ReplacementItemInterface::UNIT_PRICE_AMOUNT => '62.5000',
                ReplacementItemInterface::ROW_TOTAL_AMOUNT => '125.0000',
            ]])
        );
    }

    public function testFullyRejectedReturnCanResolveAsRejectedExchange(): void
    {
        $exchange = $this->exchange(
            ReturnStatus::REJECTED,
            ReplacementStatus::CANCELLED,
            SettlementStatus::CANCELLED,
            '0.0000',
            '0.0000'
        );
        $this->completionValidator()->assertRejectedOutcome(
            $exchange,
            [[
                ReturnItemInterface::RECEIPT_RESOLVED => 1,
                ReturnItemInterface::RECEIVED_QTY => '1.0000',
                ReturnItemInterface::ACCEPTED_QTY => '0.0000',
                ReturnItemInterface::REJECTED_QTY => '1.0000',
                ReturnItemInterface::UNIT_CREDIT_AMOUNT => '100.0000',
                ReturnItemInterface::ROW_CREDIT_AMOUNT => '0.0000',
            ]],
            [],
            []
        );

        self::assertTrue(true);
    }

    public function testAcceptedReturnSupportsRefundOnlyCompletion(): void
    {
        $exchange = $this->exchange(
            ReturnStatus::ACCEPTED,
            ReplacementStatus::CANCELLED,
            SettlementStatus::REFUND_ISSUED,
            '100.0000',
            '-100.0000'
        );
        $rows = [
            [
                SettlementInterface::STATUS => EntryStatus::SUCCEEDED,
                SettlementInterface::TYPE => Type::RETURN_CREDIT,
                SettlementInterface::AMOUNT => '100.0000',
                SettlementInterface::CURRENCY_CODE => 'EGP',
            ],
            [
                SettlementInterface::STATUS => EntryStatus::SUCCEEDED,
                SettlementInterface::TYPE => Type::MERCHANT_REFUND,
                SettlementInterface::AMOUNT => '-100.0000',
                SettlementInterface::CURRENCY_CODE => 'EGP',
                SettlementInterface::EXTERNAL_REFERENCE => 'refund-100',
            ],
        ];
        $this->completionValidator()->execute(
            $exchange,
            [[
                ReturnItemInterface::RECEIPT_RESOLVED => 1,
                ReturnItemInterface::RECEIVED_QTY => '1.0000',
                ReturnItemInterface::ACCEPTED_QTY => '1.0000',
                ReturnItemInterface::CREDITED_QTY => '1.0000',
                ReturnItemInterface::REJECTED_QTY => '0.0000',
                ReturnItemInterface::UNIT_CREDIT_AMOUNT => '100.0000',
                ReturnItemInterface::ROW_CREDIT_AMOUNT => '100.0000',
            ]],
            [],
            $rows
        );

        self::assertTrue(true);
    }

    public function testOverallCancellationRequiresLedgerRowsToBeCancelledFirst(): void
    {
        $exchange = $this->createMock(ExchangeInterface::class);
        $exchange->method('getExchangeStatus')->willReturn(ExchangeStatus::IN_PROGRESS);
        $exchange->method('getReturnStatus')->willReturn(ReturnStatus::AUTHORIZED);
        $exchange->method('getReplacementStatus')->willReturn(ReplacementStatus::PENDING);
        $exchange->method('getSettlementStatus')->willReturn(SettlementStatus::PENDING);
        $this->expectException(InvariantViolationException::class);

        (new WorkflowCoordinator(new DecimalMath(), new DecimalMath(4, 12)))->execute(
            $exchange,
            StateDimension::EXCHANGE,
            ExchangeStatus::CANCELLED,
            [],
            [],
            [[SettlementInterface::STATUS => EntryStatus::PENDING]]
        );
    }

    public function testAggregateChildMutationAdvancesParentVersionOnce(): void
    {
        $resource = $this->createMock(ExchangeResource::class);
        $resource->expects(self::once())
            ->method('updateVersion')
            ->with(4, 7, 8)
            ->willReturn(true);

        self::assertSame(
            8,
            (new AggregateVersionBumper($resource, new VersionGuard()))->execute(4, 7)
        );
    }

    public function testLateIdempotentReplayAcceptsTheSameFinancialIntent(): void
    {
        $requested = $this->settlement(4, Type::MERCHANT_REFUND, '-10', 'EGP');
        $persisted = $this->settlement(4, Type::MERCHANT_REFUND, '-10.0000', 'EGP');

        (new SettlementIntentValidator(new DecimalMath()))->execute($requested, $persisted);
        self::assertTrue(true);
    }

    public function testIdempotencyCollisionRejectsDifferentFinancialIntent(): void
    {
        $requested = $this->settlement(4, Type::MERCHANT_REFUND, '-10.0000', 'EGP');
        $persisted = $this->settlement(4, Type::MERCHANT_REFUND, '-11.0000', 'EGP');
        $this->expectException(InvariantViolationException::class);

        (new SettlementIntentValidator(new DecimalMath()))->execute($requested, $persisted);
    }

    private function completionValidator(): CompletionValidator
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

    private function exchange(
        string $returnStatus,
        string $replacementStatus,
        string $settlementStatus,
        string $returnCredit,
        string $balance
    ): ExchangeInterface {
        $exchange = $this->createMock(ExchangeInterface::class);
        $exchange->method('getReturnStatus')->willReturn($returnStatus);
        $exchange->method('getReplacementStatus')->willReturn($replacementStatus);
        $exchange->method('getSettlementStatus')->willReturn($settlementStatus);
        $exchange->method('getReturnCreditAmount')->willReturn($returnCredit);
        $exchange->method('getNativeReturnCreditAmount')->willReturn($returnCredit);
        $exchange->method('getBaseNativeReturnCreditAmount')->willReturn($returnCredit);
        $exchange->method('getNativeReplacementAmount')->willReturn('0.0000');
        $exchange->method('getBaseNativeReplacementAmount')->willReturn('0.0000');
        $exchange->method('getReplacementAmount')->willReturn('0.0000');
        $exchange->method('getShippingAmount')->willReturn('0.0000');
        $exchange->method('getFeeAmount')->willReturn('0.0000');
        $exchange->method('getBalanceAmount')->willReturn($balance);
        $exchange->method('getCurrencyCode')->willReturn('EGP');

        return $exchange;
    }

    private function settlement(
        int $exchangeId,
        string $type,
        string $amount,
        string $currency
    ): SettlementInterface {
        $settlement = $this->createMock(SettlementInterface::class);
        $settlement->method('getExchangeId')->willReturn($exchangeId);
        $settlement->method('getType')->willReturn($type);
        $settlement->method('getAmount')->willReturn($amount);
        $settlement->method('getCurrencyCode')->willReturn($currency);

        return $settlement;
    }
}
