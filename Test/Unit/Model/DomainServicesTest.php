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
use Bonlineco\SalesExchange\Api\Status\ReturnStatus;
use Bonlineco\SalesExchange\Api\Status\SettlementStatus;
use Bonlineco\SalesExchange\Api\Status\StateDimension;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\AllocationValidator;
use Bonlineco\SalesExchange\Model\BalanceCalculator;
use Bonlineco\SalesExchange\Model\CompletionValidator;
use Bonlineco\SalesExchange\Model\Creditmemo\ReturnCreditProjection;
use Bonlineco\SalesExchange\Model\FinancialAggregateCalculator;
use Bonlineco\SalesExchange\Model\FinancialRowCalculator;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Bonlineco\SalesExchange\Model\NativeReplacementProjection;
use Bonlineco\SalesExchange\Model\ReplacementCurrencyCalculator;
use Bonlineco\SalesExchange\Model\RemainingQuantityCalculator;
use Bonlineco\SalesExchange\Model\ReturnableOrderItemValidator;
use Bonlineco\SalesExchange\Model\ReturnItemQuantityValidator;
use Bonlineco\SalesExchange\Model\StateTransitionGuard;
use Magento\Sales\Api\Data\OrderItemInterface;
use PHPUnit\Framework\TestCase;

/**
 * Cross-version PHPUnit coverage for the pure Phase 1 domain rules.
 */
class DomainServicesTest extends TestCase
{
    public function testBalanceCalculatorSupportsCustomerAndMerchantBalances(): void
    {
        $calculator = new BalanceCalculator(new DecimalMath());

        self::assertSame('250.0000', $calculator->execute('1250', '0', '0', '1000'));
        self::assertSame('-100.0000', $calculator->execute('900', '0', '0', '1000'));
    }

    public function testDecimalMathRejectsExcessScaleAndPrecision(): void
    {
        $math = new DecimalMath(4, 12);
        $this->expectException(InvariantViolationException::class);

        $math->normalize('123456789.0000');
    }

    public function testRemainingQuantityAndAllocationRules(): void
    {
        $math = new DecimalMath(4, 12);
        $remaining = (new RemainingQuantityCalculator($math))
            ->execute('5', '1.5', '0.5');
        self::assertSame('3.0000', $remaining);
        (new AllocationValidator($math))->execute('3', $remaining);

        $this->expectException(InvariantViolationException::class);
        (new AllocationValidator($math))->execute('3.0001', $remaining);
    }

    public function testReturnQuantityConservationRejectsOverInspection(): void
    {
        $validator = new ReturnItemQuantityValidator(new DecimalMath(4, 12));
        $this->expectException(InvariantViolationException::class);

        $validator->execute('2', '2', '1', '1', '1');
    }

    public function testStateTransitionGuardAllowsOnlyDeclaredEdges(): void
    {
        $guard = new StateTransitionGuard();
        $guard->execute(
            StateDimension::EXCHANGE,
            ExchangeStatus::DRAFT,
            ExchangeStatus::PENDING_APPROVAL
        );

        $this->expectException(InvariantViolationException::class);
        $guard->execute(
            StateDimension::EXCHANGE,
            ExchangeStatus::DRAFT,
            ExchangeStatus::COMPLETED
        );
    }

    public function testCanonicalReturnItemRejectsCompositeChild(): void
    {
        $item = $this->createMock(OrderItemInterface::class);
        $item->method('getParentItemId')->willReturn(42);
        $item->method('getProductType')->willReturn('simple');
        $this->expectException(InvariantViolationException::class);

        (new ReturnableOrderItemValidator())->execute($item);
    }

    public function testCanonicalReturnItemAcceptsConfigurableParent(): void
    {
        $item = $this->createMock(OrderItemInterface::class);
        $item->method('getParentItemId')->willReturn(null);
        $item->method('getProductType')->willReturn('configurable');

        (new ReturnableOrderItemValidator())->execute($item);
        self::assertTrue(true);
    }

    public function testCompletionRequiresReconciledSuccessfulRecords(): void
    {
        $exchange = $this->createMock(ExchangeInterface::class);
        $exchange->method('getReturnStatus')->willReturn(ReturnStatus::ACCEPTED);
        $exchange->method('getReplacementStatus')->willReturn('delivered');
        $exchange->method('getSettlementStatus')->willReturn(SettlementStatus::PAYMENT_RECEIVED);
        $exchange->method('getReturnCreditAmount')->willReturn('1000.0000');
        $exchange->method('getNativeReturnCreditAmount')->willReturn('1000.0000');
        $exchange->method('getNativeReplacementAmount')->willReturn('1250.0000');
        $exchange->method('getReplacementAmount')->willReturn('1250.0000');
        $exchange->method('getBalanceAmount')->willReturn('250.0000');
        $exchange->method('getShippingAmount')->willReturn('0.0000');
        $exchange->method('getFeeAmount')->willReturn('0.0000');
        $exchange->method('getCurrencyCode')->willReturn('EGP');
        $returnRows = [[
            ReturnItemInterface::RECEIPT_RESOLVED => 1,
            ReturnItemInterface::RECEIVED_QTY => '1.0000',
            ReturnItemInterface::ACCEPTED_QTY => '1.0000',
            ReturnItemInterface::CREDITED_QTY => '1.0000',
            ReturnItemInterface::REJECTED_QTY => '0.0000',
            ReturnItemInterface::UNIT_CREDIT_AMOUNT => '1000.0000',
            ReturnItemInterface::ROW_CREDIT_AMOUNT => '1000.0000',
        ]];
        $replacementRows = [[
            ReplacementItemInterface::REPLACEMENT_ORDER_ITEM_ID => 10,
            ReplacementItemInterface::SKU => 'replacement-sku',
            ReplacementItemInterface::NAME => 'Replacement',
            ReplacementItemInterface::QTY => '1.0000',
            ReplacementItemInterface::UNIT_PRICE_AMOUNT => '1250.0000',
            ReplacementItemInterface::ROW_TOTAL_AMOUNT => '1250.0000',
        ]];
        $settlementRows = [
            [
                SettlementInterface::STATUS => EntryStatus::SUCCEEDED,
                SettlementInterface::TYPE => Type::RETURN_CREDIT,
                SettlementInterface::AMOUNT => '1000.0000',
                SettlementInterface::CURRENCY_CODE => 'EGP',
            ],
            [
                SettlementInterface::STATUS => EntryStatus::SUCCEEDED,
                SettlementInterface::TYPE => Type::CUSTOMER_PAYMENT,
                SettlementInterface::AMOUNT => '250.0000',
                SettlementInterface::CURRENCY_CODE => 'EGP',
                SettlementInterface::EXTERNAL_REFERENCE => 'payment-250',
            ],
        ];
        $moneyMath = new DecimalMath();
        $quantityMath = new DecimalMath(4, 12);
        $rowCalculator = new FinancialRowCalculator($moneyMath, $quantityMath);
        $validator = new CompletionValidator(
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

        $validator->execute($exchange, $returnRows, $replacementRows, $settlementRows);
        self::assertTrue(true);
    }

    public function testCompletionRejectsCancelledSettlement(): void
    {
        $exchange = $this->createMock(ExchangeInterface::class);
        $exchange->method('getReturnStatus')->willReturn(ReturnStatus::ACCEPTED);
        $exchange->method('getReplacementStatus')->willReturn('delivered');
        $exchange->method('getSettlementStatus')->willReturn(SettlementStatus::CANCELLED);
        $exchange->method('getReturnCreditAmount')->willReturn('0.0000');
        $exchange->method('getReplacementAmount')->willReturn('0.0000');
        $exchange->method('getBalanceAmount')->willReturn('0.0000');
        $exchange->method('getShippingAmount')->willReturn('0.0000');
        $exchange->method('getFeeAmount')->willReturn('0.0000');
        $exchange->method('getNativeReplacementAmount')->willReturn('0.0000');
        $this->expectException(InvariantViolationException::class);

        $moneyMath = new DecimalMath();
        $quantityMath = new DecimalMath(4, 12);
        $rowCalculator = new FinancialRowCalculator($moneyMath, $quantityMath);
        (new CompletionValidator(
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
        ))->execute(
            $exchange,
            [[
                ReturnItemInterface::RECEIPT_RESOLVED => 1,
                ReturnItemInterface::RECEIVED_QTY => '1.0000',
                ReturnItemInterface::ACCEPTED_QTY => '1.0000',
                ReturnItemInterface::CREDITED_QTY => '1.0000',
                ReturnItemInterface::REJECTED_QTY => '0.0000',
                ReturnItemInterface::UNIT_CREDIT_AMOUNT => '0.0000',
                ReturnItemInterface::ROW_CREDIT_AMOUNT => '0.0000',
            ]],
            [[
                ReplacementItemInterface::REPLACEMENT_ORDER_ITEM_ID => 10,
                ReplacementItemInterface::SKU => 'replacement-sku',
                ReplacementItemInterface::NAME => 'Replacement',
                ReplacementItemInterface::QTY => '1.0000',
                ReplacementItemInterface::UNIT_PRICE_AMOUNT => '0.0000',
                ReplacementItemInterface::ROW_TOTAL_AMOUNT => '0.0000',
            ]],
            []
        );
    }
}
