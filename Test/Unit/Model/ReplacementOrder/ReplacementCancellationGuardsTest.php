<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Unit\Model\ReplacementOrder;

use Bonlineco\SalesExchange\Api\Data\ExchangeInterface;
use Bonlineco\SalesExchange\Api\Data\ReplacementItemInterface;
use Bonlineco\SalesExchange\Api\Status\ExchangeStatus;
use Bonlineco\SalesExchange\Api\Status\ReplacementStatus;
use Bonlineco\SalesExchange\Api\Status\ReturnStatus;
use Bonlineco\SalesExchange\Api\Status\SettlementStatus;
use Bonlineco\SalesExchange\Api\Status\StateDimension;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Bonlineco\SalesExchange\Model\StateTransitionGuard;
use Bonlineco\SalesExchange\Model\WorkflowCoordinator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verify READY cancellation remains available only through the strong guard.
 */
class ReplacementCancellationGuardsTest extends TestCase
{
    /**
     * @dataProvider nativeDocumentAdvanceProvider
     */
    #[DataProvider('nativeDocumentAdvanceProvider')]
    public function testGenericTransitionsRejectNativeDocumentAdvances(
        string $fromStatus,
        string $toStatus
    ): void {
        $this->expectException(InvariantViolationException::class);

        (new StateTransitionGuard())->execute(
            StateDimension::REPLACEMENT,
            $fromStatus,
            $toStatus
        );
    }

    public function testGenericPendingCancellationRemainsAvailable(): void
    {
        (new StateTransitionGuard())->execute(
            StateDimension::REPLACEMENT,
            ReplacementStatus::PENDING,
            ReplacementStatus::CANCELLED
        );

        self::assertTrue(true);
    }

    public function testGenericReadyCancellationRemainsForbidden(): void
    {
        $this->expectException(InvariantViolationException::class);

        (new StateTransitionGuard())->execute(
            StateDimension::REPLACEMENT,
            ReplacementStatus::READY,
            ReplacementStatus::CANCELLED
        );
    }

    /**
     * @dataProvider cancellableStatusProvider
     */
    #[DataProvider('cancellableStatusProvider')]
    public function testSpecializedGuardAcceptsOnlyPreNativeStatuses(
        string $status
    ): void {
        (new StateTransitionGuard())
            ->executeReplacementIntentCancellation($status);

        self::assertTrue(true);
    }

    public function testSpecializedGuardRejectsOrderedReplacement(): void
    {
        $this->expectException(InvariantViolationException::class);

        (new StateTransitionGuard())
            ->executeReplacementIntentCancellation(
                ReplacementStatus::ORDERED
            );
    }

    public function testNativeCancellationGuardAcceptsOrderedAndReplay(): void
    {
        $guard = new StateTransitionGuard();

        $guard->executeNativeReplacementCancellation(
            ReplacementStatus::ORDERED
        );
        $guard->executeNativeReplacementCancellation(
            ReplacementStatus::CANCELLED
        );

        self::assertTrue(true);
    }

    public function testNativeShipmentGuardAcceptsOnlyOrdered(): void
    {
        (new StateTransitionGuard())->executeNativeReplacementShipment(
            ReplacementStatus::ORDERED
        );

        self::assertTrue(true);
    }

    public function testNativeShipmentGuardRejectsShippedReplayAsTransition(): void
    {
        $this->expectException(InvariantViolationException::class);

        (new StateTransitionGuard())->executeNativeReplacementShipment(
            ReplacementStatus::SHIPPED
        );
    }

    public function testDeliveryGuardAcceptsShippedAndDeliveredReplay(): void
    {
        $guard = new StateTransitionGuard();

        $guard->executeProvenReplacementDelivery(
            ReplacementStatus::SHIPPED
        );
        $guard->executeProvenReplacementDelivery(
            ReplacementStatus::DELIVERED
        );

        self::assertTrue(true);
    }

    public function testDeliveryGuardRejectsUnshippedReplacement(): void
    {
        $this->expectException(InvariantViolationException::class);

        (new StateTransitionGuard())->executeProvenReplacementDelivery(
            ReplacementStatus::ORDERED
        );
    }

    public function testCoordinatorAllowsReadyRefundOnlyCompensation(): void
    {
        $exchange = $this->exchange(ReplacementStatus::READY);

        (new WorkflowCoordinator(
            new DecimalMath(),
            new DecimalMath(4, 12)
        ))->assertReplacementIntentCancellation(
            $exchange,
            [[
                ReplacementItemInterface::REPLACEMENT_ORDER_ITEM_ID => null,
            ]]
        );

        self::assertTrue(true);
    }

    public function testCoordinatorRejectsNativeItemLink(): void
    {
        $this->expectException(InvariantViolationException::class);

        (new WorkflowCoordinator(
            new DecimalMath(),
            new DecimalMath(4, 12)
        ))->assertReplacementIntentCancellation(
            $this->exchange(ReplacementStatus::READY),
            [[
                ReplacementItemInterface::REPLACEMENT_ORDER_ITEM_ID => 501,
            ]]
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function cancellableStatusProvider(): array
    {
        return [
            'pending' => [ReplacementStatus::PENDING],
            'ready' => [ReplacementStatus::READY],
            'idempotent replay' => [ReplacementStatus::CANCELLED],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function nativeDocumentAdvanceProvider(): array
    {
        return [
            'intent freeze requires the placement command' => [
                ReplacementStatus::PENDING,
                ReplacementStatus::READY,
            ],
            'native order requires committed-order proof' => [
                ReplacementStatus::READY,
                ReplacementStatus::ORDERED,
            ],
            'shipment requires native shipment proof' => [
                ReplacementStatus::ORDERED,
                ReplacementStatus::SHIPPED,
            ],
            'delivery requires native delivery proof' => [
                ReplacementStatus::SHIPPED,
                ReplacementStatus::DELIVERED,
            ],
        ];
    }

    private function exchange(string $replacementStatus): ExchangeInterface
    {
        $exchange = $this->createMock(ExchangeInterface::class);
        $exchange->method('getExchangeStatus')
            ->willReturn(ExchangeStatus::IN_PROGRESS);
        $exchange->method('getReturnStatus')
            ->willReturn(ReturnStatus::ACCEPTED);
        $exchange->method('getReplacementStatus')
            ->willReturn($replacementStatus);
        $exchange->method('getSettlementStatus')
            ->willReturn(SettlementStatus::PENDING);

        return $exchange;
    }
}
