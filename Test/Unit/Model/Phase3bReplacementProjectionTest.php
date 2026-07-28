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
use Bonlineco\SalesExchange\Api\Status\ReplacementStatus;
use Bonlineco\SalesExchange\Api\Status\ReturnStatus;
use Bonlineco\SalesExchange\Api\Status\SettlementStatus;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\BalanceCalculator;
use Bonlineco\SalesExchange\Model\CompletionValidator;
use Bonlineco\SalesExchange\Model\Creditmemo\ReturnCreditProjection;
use Bonlineco\SalesExchange\Model\FinancialAggregateCalculator;
use Bonlineco\SalesExchange\Model\FinancialRowCalculator;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Bonlineco\SalesExchange\Model\NativeReplacementProjection;
use Bonlineco\SalesExchange\Model\ReplacementCurrencyCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Focused coverage for the native replacement accounting foundation.
 */
class Phase3bReplacementProjectionTest extends TestCase
{
    public function testPendingReplacementHasNoFinancialEffect(): void
    {
        self::assertSame(
            '0.0000',
            $this->projection()->execute(
                ReplacementStatus::PENDING,
                '100.0000',
                '10.0000',
                '125.0000'
            )
        );
    }

    public function testReadyReplacementUsesApprovedMerchandiseAndShipping(): void
    {
        self::assertSame(
            '110.0000',
            $this->projection()->execute(
                ReplacementStatus::READY,
                '100.0000',
                '10.0000',
                '125.0000'
            )
        );
    }

    public function testOrderedReplacementUsesZeroNativeActualWithoutFallback(): void
    {
        self::assertSame(
            '0.0000',
            $this->projection()->execute(
                ReplacementStatus::ORDERED,
                '100.0000',
                '10.0000',
                '0.0000'
            )
        );
    }

    public function testDeliveredReplacementUsesNativeActual(): void
    {
        self::assertSame(
            '125.0000',
            $this->projection()->execute(
                ReplacementStatus::DELIVERED,
                '100.0000',
                '10.0000',
                '125.0000'
            )
        );
    }

    public function testCancelledReplacementKeepsSnapshotsButProjectsZero(): void
    {
        self::assertSame(
            '0.0000',
            $this->projection()->execute(
                ReplacementStatus::CANCELLED,
                '100.0000',
                '10.0000',
                '125.0000'
            )
        );
    }

    public function testProjectionRejectsUnknownReplacementStatus(): void
    {
        $this->expectException(InvariantViolationException::class);

        $this->projection()->execute('unknown', '0', '0', '0');
    }

    public function testCancelledCompletionRetainsApprovedSnapshotWithoutNativeSideEffects(): void
    {
        $this->completionValidator()->execute(
            $this->cancelledExchange(),
            $this->acceptedReturnRows(),
            $this->cancelledReplacementRows(),
            $this->refundSettlementRows()
        );

        self::assertTrue(true);
    }

    /**
     * @dataProvider cancelledNativeSideEffectProvider
     */
    #[DataProvider('cancelledNativeSideEffectProvider')]
    public function testCancelledCompletionRejectsNativeSideEffects(
        string $feeAmount,
        string $nativeAmount,
        string $baseNativeAmount,
        ?int $replacementOrderItemId
    ): void {
        $this->expectException(InvariantViolationException::class);

        $this->completionValidator()->execute(
            $this->cancelledExchange($feeAmount, $nativeAmount, $baseNativeAmount),
            $this->acceptedReturnRows(),
            $this->cancelledReplacementRows($replacementOrderItemId),
            $this->refundSettlementRows()
        );
    }

    /**
     * @return array<string, array{string, string, string, int|null}>
     */
    public static function cancelledNativeSideEffectProvider(): array
    {
        return [
            'fee' => ['5.0000', '0.0000', '0.0000', null],
            'native order total' => ['0.0000', '125.0000', '0.0000', null],
            'base native order total' => ['0.0000', '0.0000', '125.0000', null],
            'native order item link' => ['0.0000', '0.0000', '0.0000', 71],
        ];
    }

    private function projection(): NativeReplacementProjection
    {
        return new NativeReplacementProjection(new DecimalMath());
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

    private function cancelledExchange(
        string $feeAmount = '0.0000',
        string $nativeAmount = '0.0000',
        string $baseNativeAmount = '0.0000'
    ): ExchangeInterface {
        $exchange = $this->createMock(ExchangeInterface::class);
        $exchange->method('getReturnStatus')->willReturn(ReturnStatus::ACCEPTED);
        $exchange->method('getReplacementStatus')->willReturn(ReplacementStatus::CANCELLED);
        $exchange->method('getSettlementStatus')->willReturn(SettlementStatus::REFUND_ISSUED);
        $exchange->method('getReturnCreditAmount')->willReturn('100.0000');
        $exchange->method('getNativeReturnCreditAmount')->willReturn('100.0000');
        $exchange->method('getBaseNativeReturnCreditAmount')->willReturn('100.0000');
        $exchange->method('getReplacementAmount')->willReturn('100.0000');
        $exchange->method('getShippingAmount')->willReturn('10.0000');
        $exchange->method('getFeeAmount')->willReturn($feeAmount);
        $exchange->method('getNativeReplacementAmount')->willReturn($nativeAmount);
        $exchange->method('getBaseNativeReplacementAmount')->willReturn($baseNativeAmount);
        $exchange->method('getBalanceAmount')->willReturn('-100.0000');
        $exchange->method('getCurrencyCode')->willReturn('EGP');

        return $exchange;
    }

    /**
     * @return array<int, array<string, int|string>>
     */
    private function acceptedReturnRows(): array
    {
        return [[
            ReturnItemInterface::RECEIPT_RESOLVED => 1,
            ReturnItemInterface::RECEIVED_QTY => '1.0000',
            ReturnItemInterface::ACCEPTED_QTY => '1.0000',
            ReturnItemInterface::CREDITED_QTY => '1.0000',
            ReturnItemInterface::REJECTED_QTY => '0.0000',
            ReturnItemInterface::UNIT_CREDIT_AMOUNT => '100.0000',
            ReturnItemInterface::ROW_CREDIT_AMOUNT => '100.0000',
        ]];
    }

    /**
     * @return array<int, array<string, int|string|null>>
     */
    private function cancelledReplacementRows(?int $replacementOrderItemId = null): array
    {
        return [[
            ReplacementItemInterface::REPLACEMENT_ORDER_ITEM_ID => $replacementOrderItemId,
            ReplacementItemInterface::SKU => 'replacement-sku',
            ReplacementItemInterface::NAME => 'Replacement',
            ReplacementItemInterface::QTY => '1.0000',
            ReplacementItemInterface::UNIT_PRICE_AMOUNT => '100.0000',
            ReplacementItemInterface::ROW_TOTAL_AMOUNT => '100.0000',
        ]];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function refundSettlementRows(): array
    {
        return [
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
    }
}
