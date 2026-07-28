<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Unit\Model\NativeRefund;

use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\CanonicalRefundedQuantity;
use Bonlineco\SalesExchange\Model\Creditmemo\ExecutionContext;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Bonlineco\SalesExchange\Model\NativeRefund\ReservationGuard;
use Bonlineco\SalesExchange\Model\Order\FreshOrderLoader;
use Bonlineco\SalesExchange\Model\RemainingQuantityCalculator;
use Bonlineco\SalesExchange\Model\ResourceModel\AllocationGuard;
use Bonlineco\SalesExchange\Model\ResourceModel\ReturnItem as ReturnItemResource;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Api\Data\CreditmemoItemInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Creditmemo\Item as CreditmemoItem;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Native-refund protection for active exchange quantity reservations.
 */
class ReservationGuardTest extends TestCase
{
    private const ORDER_ID = 5;

    private const OPERATION_KEY = 'creditmemo:exchange:7:version:4';

    public function testAllowsOnlyTheUnreservedInvoicedQuantity(): void
    {
        $item = $this->orderItem(10, '2.0000', '0.0000');
        $order = $this->order([$item]);
        $locks = [];
        $reads = [];
        $guard = $this->guard(
            $order,
            [10 => '1.0000'],
            $locks,
            $reads
        );

        $guard->execute($this->creditmemo([[10, '1.0000']]), $order);

        self::assertSame([10], $locks);
        self::assertSame([10], $reads);
    }

    public function testBlocksARefundThatConsumesReservedQuantity(): void
    {
        $item = $this->orderItem(10, '2.0000', '0.0000');
        $order = $this->order([$item]);
        $locks = [];
        $reads = [];
        $guard = $this->guard(
            $order,
            [10 => '1.0000'],
            $locks,
            $reads
        );

        try {
            $guard->execute($this->creditmemo([[10, '2.0000']]), $order);
            self::fail('A native refund must not consume exchange-reserved quantity.');
        } catch (InvariantViolationException $exception) {
            self::assertSame([10], $locks);
            self::assertSame([10], $reads);
        }
    }

    public function testRejectsAStalePassedOrderSnapshot(): void
    {
        $freshOrder = $this->order([
            $this->orderItem(10, '2.0000', '0.0000'),
        ]);
        $passedOrder = $this->order([
            $this->orderItem(10, '3.0000', '0.0000'),
        ]);
        $locks = [];
        $reads = [];
        $guard = $this->guard($freshOrder, [10 => '0.0000'], $locks, $reads);

        try {
            $guard->execute($this->creditmemo([[10, '1.0000']]), $passedOrder);
            self::fail('A stale native-refund order snapshot must be rejected.');
        } catch (InvariantViolationException $exception) {
            self::assertSame([10], $locks);
            self::assertSame([], $reads);
        }
    }

    public function testCountsConfigurableParentAndChildOnlyOnce(): void
    {
        $parent = $this->orderItem(
            10,
            '2.0000',
            '0.0000',
            'configurable'
        );
        $child = $this->orderItem(11, '2.0000', '0.0000', 'simple', 10);
        $order = $this->order([$parent, $child]);
        $locks = [];
        $reads = [];
        $guard = $this->guard($order, [10 => '1.0000'], $locks, $reads);

        $guard->execute(
            $this->creditmemo([
                [10, '1.0000'],
                [11, '1.0000'],
            ]),
            $order
        );

        self::assertSame([10], $locks);
        self::assertSame([10], $reads);
    }

    public function testMapsAndLocksChildOnlyRefundThenRejectsItsShape(): void
    {
        $parent = $this->orderItem(
            10,
            '1.0000',
            '0.0000',
            'configurable'
        );
        $child = $this->orderItem(11, '1.0000', '0.0000', 'simple', 10);
        $order = $this->order([$parent, $child]);
        $locks = [];
        $reads = [];
        $guard = $this->guard($order, [10 => '1.0000'], $locks, $reads);

        try {
            $guard->execute($this->creditmemo([[11, '1.0000']]), $order);
            self::fail('A child-only refund must consume the parent availability.');
        } catch (InvariantViolationException $exception) {
            self::assertSame([10], $locks);
            self::assertSame([], $reads);
        }
    }

    public function testPriorChildOnlyRefundConsumesParentAvailability(): void
    {
        $parent = $this->orderItem(
            10,
            '2.0000',
            '0.0000',
            'configurable'
        );
        $child = $this->orderItem(11, '2.0000', '1.0000', 'simple', 10);
        $order = $this->order([$parent, $child]);
        $locks = [];
        $reads = [];
        $guard = $this->guard($order, [10 => '1.0000'], $locks, $reads);

        try {
            $guard->execute(
                $this->creditmemo([
                    [10, '1.0000'],
                    [11, '1.0000'],
                ]),
                $order
            );
            self::fail('Prior child-only refunds must consume parent availability.');
        } catch (InvariantViolationException $exception) {
            self::assertSame([10], $locks);
            self::assertSame([10], $reads);
        }
    }

    public function testRejectsAParentOnlyConfigurableRefund(): void
    {
        $parent = $this->orderItem(
            10,
            '2.0000',
            '0.0000',
            'configurable'
        );
        $child = $this->orderItem(11, '2.0000', '0.0000', 'simple', 10);
        $order = $this->order([$parent, $child]);
        $locks = [];
        $reads = [];
        $guard = $this->guard($order, [10 => '0.0000'], $locks, $reads);

        try {
            $guard->execute($this->creditmemo([[10, '1.0000']]), $order);
            self::fail('A configurable refund requires its generated child.');
        } catch (InvariantViolationException $exception) {
            self::assertSame([10], $locks);
            self::assertSame([], $reads);
        }
    }

    public function testRejectsMismatchedConfigurableQuantitiesAfterLocking(): void
    {
        $parent = $this->orderItem(
            10,
            '2.0000',
            '0.0000',
            'configurable'
        );
        $child = $this->orderItem(11, '2.0000', '0.0000', 'simple', 10);
        $order = $this->order([$parent, $child]);
        $locks = [];
        $reads = [];
        $guard = $this->guard($order, [10 => '0.0000'], $locks, $reads);

        try {
            $guard->execute(
                $this->creditmemo([
                    [10, '1.0000'],
                    [11, '0.5000'],
                ]),
                $order
            );
            self::fail('Configurable parent and child quantities must match.');
        } catch (InvariantViolationException $exception) {
            self::assertSame([10], $locks);
            self::assertSame([], $reads);
        }
    }

    public function testRejectsMultiplePositiveGeneratedChildren(): void
    {
        $parent = $this->orderItem(
            10,
            '2.0000',
            '0.0000',
            'configurable'
        );
        $child11 = $this->orderItem(11, '2.0000', '0.0000', 'simple', 10);
        $child12 = $this->orderItem(12, '2.0000', '0.0000', 'simple', 10);
        $order = $this->order([$parent, $child11, $child12]);
        $locks = [];
        $reads = [];
        $guard = $this->guard($order, [10 => '0.0000'], $locks, $reads);

        try {
            $guard->execute(
                $this->creditmemo([
                    [10, '1.0000'],
                    [11, '1.0000'],
                    [12, '1.0000'],
                ]),
                $order
            );
            self::fail('A configurable refund requires one generated child.');
        } catch (InvariantViolationException $exception) {
            self::assertSame([10], $locks);
            self::assertSame([], $reads);
        }
    }

    public function testDuplicateCreditmemoRowsCannotBypassTheReservation(): void
    {
        $item = $this->orderItem(10, '1.0000', '0.0000');
        $order = $this->order([$item]);
        $locks = [];
        $reads = [];
        $guard = $this->guard($order, [10 => '0.0000'], $locks, $reads);

        try {
            $guard->execute(
                $this->creditmemo([
                    [10, '0.6000'],
                    [10, '0.6000'],
                ]),
                $order
            );
            self::fail('Duplicate rows must be aggregated before availability validation.');
        } catch (InvariantViolationException $exception) {
            self::assertSame([10], $locks);
            self::assertSame([10], $reads);
        }
    }

    public function testLocksAllocationRowsInDeterministicItemOrder(): void
    {
        $item20 = $this->orderItem(20, '1.0000', '0.0000');
        $item10 = $this->orderItem(10, '1.0000', '0.0000');
        $order = $this->order([$item20, $item10]);
        $locks = [];
        $reads = [];
        $guard = $this->guard(
            $order,
            [10 => '0.0000', 20 => '0.0000'],
            $locks,
            $reads
        );

        $guard->execute(
            $this->creditmemo([
                [20, '1.0000'],
                [10, '1.0000'],
            ]),
            $order
        );

        self::assertSame([10, 20], $locks);
        self::assertSame([10, 20], $reads);
    }

    public function testBlocksOrderCurrencyAdjustmentOnlyWhenAllocationIsActive(): void
    {
        $item = $this->orderItem(10, '1.0000', '0.0000');
        $order = $this->order([$item]);
        $locks = [];
        $reads = [];
        $events = [];
        $guard = $this->guard(
            $order,
            [10 => '1.0000'],
            $locks,
            $reads,
            $events,
            true
        );
        $creditmemo = $this->creditmemo(
            [[10, '0.0000']],
            '50.0000',
            '0.0000'
        );

        try {
            $guard->execute($creditmemo, $order);
            self::fail('An adjustment-only refund must preserve exchange financial capacity.');
        } catch (InvariantViolationException $exception) {
            self::assertSame([10], $locks);
            self::assertSame([], $reads);
            self::assertSame(['lock:10', 'order-allocation-read'], $events);
        }
    }

    public function testBlocksBaseAdjustmentOnUnreservedLineWhenAnotherIsReserved(): void
    {
        $reserved = $this->orderItem(20, '1.0000', '0.0000');
        $unreserved = $this->orderItem(10, '1.0000', '0.0000');
        $order = $this->order([$reserved, $unreserved]);
        $locks = [];
        $reads = [];
        $events = [];
        $guard = $this->guard(
            $order,
            [10 => '0.0000', 20 => '1.0000'],
            $locks,
            $reads,
            $events,
            true
        );
        $creditmemo = $this->creditmemo(
            [[10, '1.0000']],
            '0.0000',
            '25.0000'
        );

        try {
            $guard->execute($creditmemo, $order);
            self::fail('An unreserved item must not carry an adjustment over another reservation.');
        } catch (InvariantViolationException $exception) {
            self::assertSame([10, 20], $locks);
            self::assertSame([], $reads);
            self::assertSame(
                ['lock:10', 'lock:20', 'order-allocation-read'],
                $events
            );
        }
    }

    public function testLocksWholeOrderBeforeAllocationSnapshotAndFreshRead(): void
    {
        $order = $this->order([
            $this->orderItem(30, '1.0000', '0.0000'),
            $this->orderItem(10, '1.0000', '0.0000'),
            $this->orderItem(20, '1.0000', '0.0000'),
        ]);
        $locks = [];
        $reads = [];
        $events = [];
        $guard = $this->guard(
            $order,
            [10 => '0.0000', 20 => '0.0000', 30 => '0.0000'],
            $locks,
            $reads,
            $events,
            true
        );

        $guard->execute(
            $this->creditmemo([[20, '1.0000']], '1.0000', '0.0000'),
            $order
        );

        self::assertSame([10, 20, 30], $locks);
        self::assertSame([20], $reads);
        self::assertSame(
            [
                'lock:10',
                'lock:20',
                'lock:30',
                'order-allocation-read',
                'fresh-order-read',
                'item-allocation-read:20',
            ],
            $events
        );
    }

    public function testAllowsNegativeOnlyAdjustmentWithUnrelatedActiveReservation(): void
    {
        $reserved = $this->orderItem(10, '1.0000', '0.0000');
        $refunded = $this->orderItem(20, '1.0000', '0.0000');
        $order = $this->order([$reserved, $refunded]);
        $locks = [];
        $reads = [];
        $events = [];
        $guard = $this->guard(
            $order,
            [10 => '1.0000', 20 => '0.0000'],
            $locks,
            $reads,
            $events,
            false,
            [20]
        );

        $guard->execute(
            $this->creditmemo(
                [[20, '1.0000']],
                '0.0000',
                '0.0000',
                '5.0000',
                '5.0000'
            ),
            $order
        );

        self::assertSame([20], $locks);
        self::assertSame([20], $reads);
        self::assertSame(
            ['lock:20', 'fresh-order-read', 'item-allocation-read:20'],
            $events
        );
    }

    public function testActiveExactExchangeMarkerBypassesItsOwnReservation(): void
    {
        $freshOrderLoader = $this->createMock(FreshOrderLoader::class);
        $freshOrderLoader->expects(self::never())->method('execute');
        $allocationGuard = $this->createMock(AllocationGuard::class);
        $allocationGuard->expects(self::never())->method('lock');
        $returnItemResource = $this->createMock(ReturnItemResource::class);
        $returnItemResource->expects(self::never())
            ->method('getAllocatedQuantity');
        $returnItemResource->expects(self::never())
            ->method('getAllocatedQuantityForOrder');
        $executionContext = new ExecutionContext();
        $guard = $this->newGuard(
            $freshOrderLoader,
            $allocationGuard,
            $returnItemResource,
            $executionContext
        );
        $creditmemo = $this->creditmemo([[10, '1.0000']]);
        $creditmemo->setData(
            ExecutionContext::CREDITMEMO_DATA_KEY,
            self::OPERATION_KEY
        );

        $executionContext->execute(
            self::OPERATION_KEY,
            function () use ($guard, $creditmemo): void {
                $guard->execute(
                    $creditmemo,
                    $this->createMock(OrderInterface::class)
                );
            }
        );
    }

    public function testSpoofedMarkerWithoutActiveContextIsRejected(): void
    {
        $item = $this->orderItem(10, '1.0000', '0.0000');
        $order = $this->order([$item]);
        $locks = [];
        $reads = [];
        $guard = $this->guard($order, [10 => '1.0000'], $locks, $reads);
        $creditmemo = $this->creditmemo([[10, '1.0000']]);
        $creditmemo->setData(
            ExecutionContext::CREDITMEMO_DATA_KEY,
            self::OPERATION_KEY
        );

        try {
            $guard->execute($creditmemo, $order);
            self::fail('A transient marker is not trusted outside its exact context.');
        } catch (InvariantViolationException $exception) {
            self::assertSame([10], $locks);
            self::assertSame([10], $reads);
        }
    }

    /**
     * @param array<int, OrderItemInterface> $items
     */
    private function order(array $items): OrderInterface
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(self::ORDER_ID);
        $order->method('getItems')->willReturn($items);

        return $order;
    }

    private function orderItem(
        int $itemId,
        string $invoiced,
        string $refunded,
        string $productType = 'simple',
        ?int $parentItemId = null
    ): OrderItemInterface {
        $item = $this->createMock(OrderItemInterface::class);
        $item->method('getItemId')->willReturn($itemId);
        $item->method('getOrderId')->willReturn(self::ORDER_ID);
        $item->method('getQtyInvoiced')->willReturn($invoiced);
        $item->method('getQtyRefunded')->willReturn($refunded);
        $item->method('getProductType')->willReturn($productType);
        $item->method('getParentItemId')->willReturn($parentItemId);

        return $item;
    }

    /**
     * @param array<int, array{0: int, 1: string}> $rows
     */
    private function creditmemo(
        array $rows,
        string $adjustmentPositive = '0.0000',
        string $baseAdjustmentPositive = '0.0000',
        string $adjustmentNegative = '0.0000',
        string $baseAdjustmentNegative = '0.0000'
    ): Creditmemo {
        /** @var Creditmemo $creditmemo */
        $creditmemo = (new \ReflectionClass(Creditmemo::class))
            ->newInstanceWithoutConstructor();
        $creditmemo->setData([
            CreditmemoInterface::ORDER_ID => self::ORDER_ID,
            CreditmemoInterface::ADJUSTMENT_POSITIVE => $adjustmentPositive,
            CreditmemoInterface::BASE_ADJUSTMENT_POSITIVE => $baseAdjustmentPositive,
            CreditmemoInterface::ADJUSTMENT_NEGATIVE => $adjustmentNegative,
            CreditmemoInterface::BASE_ADJUSTMENT_NEGATIVE => $baseAdjustmentNegative,
        ]);
        $items = [];
        foreach ($rows as [$orderItemId, $quantity]) {
            /** @var CreditmemoItem $item */
            $item = (new \ReflectionClass(CreditmemoItem::class))
                ->newInstanceWithoutConstructor();
            $item->setData([
                CreditmemoItemInterface::ORDER_ITEM_ID => $orderItemId,
                CreditmemoItemInterface::QTY => $quantity,
            ]);
            $items[] = $item;
        }
        $creditmemo->setItems($items);

        return $creditmemo;
    }

    /**
     * @param array<int, string> $allocations
     * @param int[] $locks
     * @param int[] $reads
     */
    private function guard(
        OrderInterface $freshOrder,
        array $allocations,
        array &$locks,
        array &$reads,
        ?array &$events = null,
        bool $expectOrderAllocationRead = false,
        ?array $expectedLocks = null
    ): ReservationGuard {
        if ($expectedLocks === null) {
            $expectedLocks = array_keys($allocations);
        }
        sort($expectedLocks, SORT_NUMERIC);
        $orderAllocation = '0.0000';
        $math = new DecimalMath(4, 12);
        foreach ($allocations as $allocation) {
            $orderAllocation = $math->add($orderAllocation, $allocation);
        }
        $freshOrderLoader = $this->createMock(FreshOrderLoader::class);
        $freshOrderLoader->method('execute')
            ->with(self::ORDER_ID)
            ->willReturnCallback(
                static function () use (
                    $freshOrder,
                    $expectedLocks,
                    &$locks,
                    &$events
                ): OrderInterface {
                    self::assertSame(
                        $expectedLocks,
                        $locks,
                        'The fresh read must happen after deterministic locks.'
                    );
                    if ($events !== null) {
                        $events[] = 'fresh-order-read';
                    }

                    return $freshOrder;
                }
            );
        $allocationGuard = $this->createMock(AllocationGuard::class);
        $allocationGuard->method('lock')
            ->willReturnCallback(
                static function (int $orderItemId) use (&$locks, &$events): void {
                    $locks[] = $orderItemId;
                    if ($events !== null) {
                        $events[] = 'lock:' . $orderItemId;
                    }
                }
            );
        $returnItemResource = $this->createMock(ReturnItemResource::class);
        $returnItemResource->expects(
            $expectOrderAllocationRead ? self::once() : self::never()
        )->method('getAllocatedQuantityForOrder')
            ->with(self::ORDER_ID)
            ->willReturnCallback(
                static function () use (
                    $expectedLocks,
                    $orderAllocation,
                    &$locks,
                    &$events
                ): string {
                    self::assertSame(
                        $expectedLocks,
                        $locks,
                        'The order allocation read must happen after every deterministic lock.'
                    );
                    if ($events !== null) {
                        $events[] = 'order-allocation-read';
                    }

                    return $orderAllocation;
                }
            );
        $returnItemResource->method('getAllocatedQuantity')
            ->willReturnCallback(
                static function (int $orderItemId) use (
                    $allocations,
                    &$reads,
                    &$events
                ): string {
                    $reads[] = $orderItemId;
                    if ($events !== null) {
                        $events[] = 'item-allocation-read:' . $orderItemId;
                    }

                    return $allocations[$orderItemId] ?? '0.0000';
                }
            );

        return $this->newGuard(
            $freshOrderLoader,
            $allocationGuard,
            $returnItemResource,
            new ExecutionContext()
        );
    }

    /**
     * @param FreshOrderLoader&MockObject $freshOrderLoader
     * @param AllocationGuard&MockObject $allocationGuard
     * @param ReturnItemResource&MockObject $returnItemResource
     */
    private function newGuard(
        FreshOrderLoader $freshOrderLoader,
        AllocationGuard $allocationGuard,
        ReturnItemResource $returnItemResource,
        ExecutionContext $executionContext
    ): ReservationGuard {
        $math = new DecimalMath(4, 12);

        return new ReservationGuard(
            $freshOrderLoader,
            $allocationGuard,
            $returnItemResource,
            new RemainingQuantityCalculator($math),
            new DecimalMath(4, 20),
            $math,
            new CanonicalRefundedQuantity($math),
            $executionContext
        );
    }
}
