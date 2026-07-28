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
use Bonlineco\SalesExchange\Api\Data\SettlementInterface;
use Bonlineco\SalesExchange\Api\Settlement\EntryStatus;
use Bonlineco\SalesExchange\Api\Settlement\Type;
use Bonlineco\SalesExchange\Api\Status\SettlementStatus;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\BalanceCalculator;
use Bonlineco\SalesExchange\Model\CompletionValidator;
use Bonlineco\SalesExchange\Model\Creditmemo\ReturnCreditProjection;
use Bonlineco\SalesExchange\Model\ExchangeRepository;
use Bonlineco\SalesExchange\Model\FinancialAggregateCalculator;
use Bonlineco\SalesExchange\Model\FinancialRowCalculator;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Bonlineco\SalesExchange\Model\NativeReplacementProjection;
use Bonlineco\SalesExchange\Model\ReplacementCurrencyCalculator;
use Bonlineco\SalesExchange\Model\ReplacementItemRepository;
use Bonlineco\SalesExchange\Model\SettlementRepository;
use PHPUnit\Framework\TestCase;

class Phase3aSettlementGuardsTest extends TestCase
{
    public function testRecordedExternalReferenceCannotBeCleared(): void
    {
        $this->expectException(InvariantViolationException::class);
        $this->invokeImmutableIntent('processor-123', null);
    }

    public function testRecordedExternalReferenceCannotBeChanged(): void
    {
        $this->expectException(InvariantViolationException::class);
        $this->invokeImmutableIntent('processor-123', 'processor-456');
    }

    public function testBlankExternalReferenceMayBeRecordedOnce(): void
    {
        $this->invokeImmutableIntent(null, 'processor-123');
        self::assertTrue(true);
    }

    public function testCompletionRejectsUnprovenSuccessfulExternalCash(): void
    {
        $exchange = $this->createMock(ExchangeInterface::class);
        $exchange->method('getBalanceAmount')->willReturn('10.0000');
        $exchange->method('getNativeReturnCreditAmount')->willReturn('0.0000');
        $exchange->method('getCurrencyCode')->willReturn('EGP');
        $this->expectException(InvariantViolationException::class);

        $this->completionValidator()->assertSettlementState(
            $exchange,
            [[
                SettlementInterface::STATUS => EntryStatus::SUCCEEDED,
                SettlementInterface::TYPE => Type::CUSTOMER_PAYMENT,
                SettlementInterface::AMOUNT => '10.0000',
                SettlementInterface::CURRENCY_CODE => 'EGP',
                SettlementInterface::EXTERNAL_REFERENCE => null,
            ]],
            SettlementStatus::PAYMENT_RECEIVED
        );
    }

    public function testGenericExchangeSaveRejectsDerivedFinancialInjection(): void
    {
        $exchange = $this->financialExchange('1.0000');
        $this->expectException(InvariantViolationException::class);

        $this->invokeExchangeWritableGuard($exchange, '0.0000');
    }

    public function testGenericExchangeNoteSavePreservesEveryDerivedTotal(): void
    {
        $this->invokeExchangeWritableGuard(
            $this->financialExchange('0.0000'),
            '0.0000'
        );
        self::assertTrue(true);
    }

    public function testGenericExchangeSaveRejectsNativeReplacementInjection(): void
    {
        $this->expectException(InvariantViolationException::class);

        $this->invokeExchangeWritableGuard(
            $this->financialExchange('0.0000'),
            '0.0000',
            '0.0000'
        );
    }

    public function testPendingReplacementCannotChangeCanonicalCatalogSnapshot(): void
    {
        $item = $this->createMock(ReplacementItemInterface::class);
        $item->method('getProductId')->willReturn(10);
        $item->method('getSku')->willReturn('replacement-sku');
        $item->method('getName')->willReturn('Replacement');
        $item->method('getQty')->willReturn('2.0000');
        $item->method('getUnitPriceAmount')->willReturn('50.0000');
        $item->method('getRowTotalAmount')->willReturn('100.0000');
        $item->method('getProductOptionsJson')->willReturn(null);
        $reflection = new \ReflectionClass(ReplacementItemRepository::class);
        $repository = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('moneyMath')->setValue($repository, new DecimalMath());
        $reflection->getProperty('quantityMath')
            ->setValue($repository, new DecimalMath(4, 12));
        $this->expectException(InvariantViolationException::class);

        $reflection->getMethod('assertCanonicalSnapshot')->invoke(
            $repository,
            $item,
            [
                ReplacementItemInterface::PRODUCT_ID => 10,
                ReplacementItemInterface::SKU => 'replacement-sku',
                ReplacementItemInterface::NAME => 'Replacement',
                ReplacementItemInterface::QTY => '1.0000',
                ReplacementItemInterface::UNIT_PRICE_AMOUNT => '50.0000',
                ReplacementItemInterface::ROW_TOTAL_AMOUNT => '50.0000',
                ReplacementItemInterface::PRODUCT_OPTIONS_JSON => null,
            ]
        );
    }

    private function invokeImmutableIntent(
        ?string $persistedReference,
        ?string $incomingReference
    ): void {
        $settlement = $this->createMock(SettlementInterface::class);
        $settlement->method('getExchangeId')->willReturn(7);
        $settlement->method('getType')->willReturn(Type::CUSTOMER_PAYMENT);
        $settlement->method('getAmount')->willReturn('10.0000');
        $settlement->method('getCurrencyCode')->willReturn('EGP');
        $settlement->method('getIdempotencyKey')->willReturn('intent-7');
        $settlement->method('getExternalReference')->willReturn($incomingReference);
        $persisted = [
            SettlementInterface::EXCHANGE_ID => 7,
            SettlementInterface::TYPE => Type::CUSTOMER_PAYMENT,
            SettlementInterface::AMOUNT => '10.0000',
            SettlementInterface::CURRENCY_CODE => 'EGP',
            SettlementInterface::IDEMPOTENCY_KEY => 'intent-7',
            SettlementInterface::EXTERNAL_REFERENCE => $persistedReference,
        ];
        $reflection = new \ReflectionClass(SettlementRepository::class);
        $repository = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('assertImmutableIntent');
        $method->invoke($repository, $settlement, $persisted);
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

    private function financialExchange(string $feeAmount): ExchangeInterface
    {
        $exchange = $this->createMock(ExchangeInterface::class);
        $exchange->method('getReturnCreditAmount')->willReturn('100.0000');
        $exchange->method('getNativeReturnCreditAmount')->willReturn('99.9900');
        $exchange->method('getBaseNativeReturnCreditAmount')->willReturn('99.9900');
        $exchange->method('getNativeReplacementAmount')->willReturn('120.0000');
        $exchange->method('getBaseNativeReplacementAmount')->willReturn('120.0000');
        $exchange->method('getReplacementAmount')->willReturn('120.0000');
        $exchange->method('getShippingAmount')->willReturn('0.0000');
        $exchange->method('getFeeAmount')->willReturn($feeAmount);
        $exchange->method('getBalanceAmount')->willReturn('20.0100');

        return $exchange;
    }

    private function invokeExchangeWritableGuard(
        ExchangeInterface $exchange,
        string $persistedFee,
        string $persistedNativeReplacement = '120.0000'
    ): void {
        $reflection = new \ReflectionClass(ExchangeRepository::class);
        $repository = $reflection->newInstanceWithoutConstructor();
        $property = $reflection->getProperty('decimalMath');
        $property->setValue($repository, new DecimalMath());
        $method = $reflection->getMethod('assertCaseIsWritable');
        $method->invoke(
            $repository,
            $exchange,
            [
                ExchangeInterface::EXCHANGE_STATUS => 'in_progress',
                ExchangeInterface::RETURN_CREDIT_AMOUNT => '100.0000',
                ExchangeInterface::NATIVE_RETURN_CREDIT_AMOUNT => '99.9900',
                ExchangeInterface::BASE_NATIVE_RETURN_CREDIT_AMOUNT => '99.9900',
                ExchangeInterface::NATIVE_REPLACEMENT_AMOUNT
                    => $persistedNativeReplacement,
                ExchangeInterface::BASE_NATIVE_REPLACEMENT_AMOUNT => '120.0000',
                ExchangeInterface::REPLACEMENT_AMOUNT => '120.0000',
                ExchangeInterface::SHIPPING_AMOUNT => '0.0000',
                ExchangeInterface::FEE_AMOUNT => $persistedFee,
                ExchangeInterface::BALANCE_AMOUNT => '20.0100',
            ]
        );
    }
}
