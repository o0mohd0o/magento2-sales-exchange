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
use Bonlineco\SalesExchange\Api\History\ActorType;
use Bonlineco\SalesExchange\Api\Status\ReturnStatus;
use Bonlineco\SalesExchange\Api\Status\StateDimension;
use Bonlineco\SalesExchange\Api\TransitionExchangeInterface;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\BalanceCalculator;
use Bonlineco\SalesExchange\Model\CanonicalRefundedQuantity;
use Bonlineco\SalesExchange\Model\CompletionValidator;
use Bonlineco\SalesExchange\Model\Creditmemo\ReturnCreditProjection;
use Bonlineco\SalesExchange\Model\Creation\RawInputValidator;
use Bonlineco\SalesExchange\Model\FinancialAggregateCalculator;
use Bonlineco\SalesExchange\Model\FinancialRowCalculator;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Bonlineco\SalesExchange\Model\NativeReplacementProjection;
use Bonlineco\SalesExchange\Model\ReplacementCurrencyCalculator;
use Bonlineco\SalesExchange\Model\OrderItemRemainingQuantity;
use Bonlineco\SalesExchange\Model\RemainingQuantityCalculator;
use Bonlineco\SalesExchange\Model\ResourceModel\ReturnItem as ReturnItemResource;
use Bonlineco\SalesExchange\Model\Workflow\InspectionFinalizer;
use Bonlineco\SalesExchange\Model\Workflow\ReturnOutcomeResolver;
use Magento\Sales\Api\Data\OrderItemInterface;
use PHPUnit\Framework\TestCase;

/**
 * Focused regression coverage for the Phase 2 remediation rules.
 */
class Phase2RemediationTest extends TestCase
{
    public function testRawInputRejectsOversizedArrayBeforeRowValidation(): void
    {
        $rows = array_fill(0, RawInputValidator::MAX_LINES + 1, 'not-a-row');
        $this->expectException(InvariantViolationException::class);

        (new RawInputValidator())->execute($rows, [], null, null);
    }

    public function testRawInputAcceptsExactlyTheLineLimit(): void
    {
        $rows = array_fill(0, RawInputValidator::MAX_LINES, []);
        (new RawInputValidator())->execute($rows, $rows, null, null);

        self::assertCount(RawInputValidator::MAX_LINES, $rows);
    }

    public function testReceiptRequiresEveryLineResolvedIncludingZeroQuantityLine(): void
    {
        $this->completionValidator()->assertReturnReceipt([
            [
                ReturnItemInterface::RECEIPT_RESOLVED => 1,
                ReturnItemInterface::ALLOCATED_QTY => '1.0000',
                ReturnItemInterface::RECEIVED_QTY => '1.0000',
            ],
            [
                ReturnItemInterface::RECEIPT_RESOLVED => 1,
                ReturnItemInterface::ALLOCATED_QTY => '1.0000',
                ReturnItemInterface::RECEIVED_QTY => '0.0000',
            ],
        ]);

        self::assertTrue(true);
    }

    public function testReceiptRejectsAnUnresolvedLine(): void
    {
        $this->expectException(InvariantViolationException::class);
        $this->completionValidator()->assertReturnReceipt([
            [
                ReturnItemInterface::RECEIPT_RESOLVED => 1,
                ReturnItemInterface::ALLOCATED_QTY => '1.0000',
                ReturnItemInterface::RECEIVED_QTY => '1.0000',
            ],
            [
                ReturnItemInterface::RECEIPT_RESOLVED => 0,
                ReturnItemInterface::ALLOCATED_QTY => '1.0000',
                ReturnItemInterface::RECEIVED_QTY => '0.0000',
            ],
        ]);
    }

    public function testAuthoritativeRemainingQuantityUsesResourceReservation(): void
    {
        $resource = $this->createMock(ReturnItemResource::class);
        $resource->expects(self::once())
            ->method('getAllocatedQuantity')
            ->with(7)
            ->willReturn('1.5000');
        $orderItem = $this->createMock(OrderItemInterface::class);
        $orderItem->method('getItemId')->willReturn(7);
        $orderItem->method('getQtyInvoiced')->willReturn(5);
        $orderItem->method('getQtyRefunded')->willReturn('0.5000');

        $service = new OrderItemRemainingQuantity(
            $resource,
            new RemainingQuantityCalculator(new DecimalMath(4, 12)),
            new CanonicalRefundedQuantity(new DecimalMath(4, 12))
        );

        self::assertSame(
            '3.0000',
            $service->execute($orderItem, [$orderItem])
        );
    }

    public function testConfigurableAvailabilityIncludesChildOnlyRefunds(): void
    {
        $resource = $this->createMock(ReturnItemResource::class);
        $resource->method('getAllocatedQuantity')
            ->with(7)
            ->willReturn('0.0000');
        $parent = $this->createMock(OrderItemInterface::class);
        $parent->method('getItemId')->willReturn(7);
        $parent->method('getOrderId')->willReturn(5);
        $parent->method('getProductType')->willReturn('configurable');
        $parent->method('getQtyInvoiced')->willReturn('2.0000');
        $parent->method('getQtyRefunded')->willReturn('0.0000');
        $child = $this->createMock(OrderItemInterface::class);
        $child->method('getItemId')->willReturn(8);
        $child->method('getOrderId')->willReturn(5);
        $child->method('getParentItemId')->willReturn(7);
        $child->method('getProductType')->willReturn('simple');
        $child->method('getQtyRefunded')->willReturn('1.0000');
        $math = new DecimalMath(4, 12);
        $service = new OrderItemRemainingQuantity(
            $resource,
            new RemainingQuantityCalculator($math),
            new CanonicalRefundedQuantity($math)
        );

        self::assertSame(
            '1.0000',
            $service->execute($parent, [$parent, $child])
        );
    }

    public function testInspectedReturnCanRecoverWithoutRewritingALine(): void
    {
        $exchange = $this->exchange(ReturnStatus::INSPECTED, 4);
        $closed = $this->exchange(ReturnStatus::ACCEPTED, 5);
        $resource = $this->createMock(ReturnItemResource::class);
        $resource->method('getRowsByExchangeId')->with(9)->willReturn([[
            ReturnItemInterface::RECEIPT_RESOLVED => 1,
            ReturnItemInterface::RECEIVED_QTY => '1.0000',
            ReturnItemInterface::ACCEPTED_QTY => '1.0000',
            ReturnItemInterface::REJECTED_QTY => '0.0000',
        ]]);
        $transition = $this->createMock(TransitionExchangeInterface::class);
        $transition->expects(self::once())
            ->method('execute')
            ->with(
                9,
                4,
                StateDimension::RETURN,
                ReturnStatus::ACCEPTED,
                ActorType::ADMIN,
                3,
                'retry'
            )
            ->willReturn($closed);
        $finalizer = new InspectionFinalizer(
            $transition,
            $resource,
            new ReturnOutcomeResolver(new DecimalMath(4, 12))
        );

        self::assertSame($closed, $finalizer->execute($exchange, 3, 'retry'));
    }

    public function testInspectionFinalizationIsIdempotentAtDerivedTerminalState(): void
    {
        $exchange = $this->exchange(ReturnStatus::ACCEPTED, 5);
        $resource = $this->createMock(ReturnItemResource::class);
        $resource->method('getRowsByExchangeId')->willReturn([[
            ReturnItemInterface::RECEIPT_RESOLVED => 1,
            ReturnItemInterface::RECEIVED_QTY => '1.0000',
            ReturnItemInterface::ACCEPTED_QTY => '1.0000',
            ReturnItemInterface::REJECTED_QTY => '0.0000',
        ]]);
        $transition = $this->createMock(TransitionExchangeInterface::class);
        $transition->expects(self::never())->method('execute');
        $finalizer = new InspectionFinalizer(
            $transition,
            $resource,
            new ReturnOutcomeResolver(new DecimalMath(4, 12))
        );

        self::assertSame($exchange, $finalizer->execute($exchange, 3, null));
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

    private function exchange(string $returnStatus, int $version): ExchangeInterface
    {
        $exchange = $this->createMock(ExchangeInterface::class);
        $exchange->method('getEntityId')->willReturn(9);
        $exchange->method('getReturnStatus')->willReturn($returnStatus);
        $exchange->method('getVersion')->willReturn($version);

        return $exchange;
    }
}
