<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Unit\Plugin;

use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\NativeRefund\ReplacementOrderGuard;
use Bonlineco\SalesExchange\Model\NativeRefund\ReservationGuard;
use Bonlineco\SalesExchange\Plugin\CreditmemoServicePlugin;
use Bonlineco\SalesExchange\Plugin\RefundAdapterPlugin;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order\RefundAdapter;
use Magento\Sales\Model\OrderMutexInterface;
use Magento\Sales\Model\Service\CreditmemoService;
use PHPUnit\Framework\TestCase;

/**
 * Mutex boundaries around Magento's native credit-memo entry points.
 */
class NativeRefundPluginsTest extends TestCase
{
    public function testRefundAdapterValidatesAndProceedsInsideOrderMutex(): void
    {
        $insideMutex = false;
        $orderMutex = $this->orderMutex($insideMutex);
        $guard = $this->createMock(ReservationGuard::class);
        $guard->expects(self::once())
            ->method('execute')
            ->willReturnCallback(
                static function () use (&$insideMutex): void {
                    self::assertTrue($insideMutex);
                }
            );
        $replacementGuard = $this->createMock(
            ReplacementOrderGuard::class
        );
        $replacementGuard->expects(self::once())
            ->method('execute')
            ->willReturnCallback(
                static function () use (&$insideMutex): void {
                    self::assertTrue($insideMutex);
                }
            );
        $plugin = new RefundAdapterPlugin(
            $orderMutex,
            $guard,
            $replacementGuard
        );
        $creditmemo = $this->creditmemo();
        $order = $this->order();
        $result = $this->createMock(OrderInterface::class);

        $actual = $plugin->aroundRefund(
            $this->refundAdapter(),
            static function (
                CreditmemoInterface $receivedCreditmemo,
                OrderInterface $receivedOrder,
                bool $isOnline
            ) use (
                &$insideMutex,
                $creditmemo,
                $order,
                $result
            ): OrderInterface {
                self::assertTrue($insideMutex);
                self::assertSame($creditmemo, $receivedCreditmemo);
                self::assertSame($order, $receivedOrder);
                self::assertTrue($isOnline);

                return $result;
            },
            $creditmemo,
            $order,
            true
        );

        self::assertSame($result, $actual);
        self::assertFalse($insideMutex);
    }

    public function testRefundAdapterDoesNotProceedWhenReservationIsBlocked(): void
    {
        $insideMutex = false;
        $orderMutex = $this->orderMutex($insideMutex);
        $guard = $this->createMock(ReservationGuard::class);
        $guard->method('execute')
            ->willThrowException(
                new InvariantViolationException(__('Reserved quantity.'))
            );
        $replacementGuard = $this->createMock(
            ReplacementOrderGuard::class
        );
        $replacementGuard->expects(self::once())->method('execute');
        $plugin = new RefundAdapterPlugin(
            $orderMutex,
            $guard,
            $replacementGuard
        );
        $proceeded = false;

        try {
            $plugin->aroundRefund(
                $this->refundAdapter(),
                static function () use (&$proceeded): OrderInterface {
                    $proceeded = true;
                    throw new \LogicException('The native refund must not run.');
                },
                $this->creditmemo(),
                $this->order()
            );
            self::fail('The reservation guard must block the native refund.');
        } catch (InvariantViolationException $exception) {
            self::assertFalse($proceeded);
            self::assertFalse($insideMutex);
        }
    }

    public function testLegacyCreditmemoServiceProceedsInsideOrderMutex(): void
    {
        $insideMutex = false;
        $orderMutex = $this->orderMutex($insideMutex);
        $plugin = new CreditmemoServicePlugin($orderMutex);
        $creditmemo = $this->creditmemo();

        $actual = $plugin->aroundRefund(
            $this->creditmemoService(),
            static function (
                CreditmemoInterface $receivedCreditmemo,
                bool $offlineRequested
            ) use (
                &$insideMutex,
                $creditmemo
            ): CreditmemoInterface {
                self::assertTrue($insideMutex);
                self::assertSame($creditmemo, $receivedCreditmemo);
                self::assertTrue($offlineRequested);

                return $receivedCreditmemo;
            },
            $creditmemo,
            true
        );

        self::assertSame($creditmemo, $actual);
        self::assertFalse($insideMutex);
    }

    public function testGlobalDiRegistersBothOutermostNativeRefundPlugins(): void
    {
        $configuration = simplexml_load_file(
            dirname(__DIR__, 3) . '/etc/di.xml'
        );
        self::assertNotFalse($configuration);

        $adapterPlugins = $configuration->xpath(
            '/config/type[@name="Magento\\Sales\\Model\\Order\\RefundAdapter"]'
            . '/plugin[@type="Bonlineco\\SalesExchange\\Plugin\\RefundAdapterPlugin"]'
        );
        $servicePlugins = $configuration->xpath(
            '/config/type[@name="Magento\\Sales\\Model\\Service\\CreditmemoService"]'
            . '/plugin[@type="Bonlineco\\SalesExchange\\Plugin\\CreditmemoServicePlugin"]'
        );

        self::assertCount(1, $adapterPlugins);
        self::assertCount(1, $servicePlugins);
        self::assertSame('-1000', (string)$adapterPlugins[0]['sortOrder']);
        self::assertSame('-1000', (string)$servicePlugins[0]['sortOrder']);
    }

    private function creditmemo(): CreditmemoInterface
    {
        $creditmemo = $this->createMock(CreditmemoInterface::class);
        $creditmemo->method('getOrderId')->willReturn(5);

        return $creditmemo;
    }

    private function order(): OrderInterface
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(5);

        return $order;
    }

    private function orderMutex(bool &$insideMutex): OrderMutexInterface
    {
        $orderMutex = $this->createMock(OrderMutexInterface::class);
        $orderMutex->expects(self::once())
            ->method('execute')
            ->with(
                5,
                self::callback(
                    static fn ($value): bool => is_callable($value)
                )
            )
            ->willReturnCallback(
                static function (
                    int $orderId,
                    callable $callback,
                    array $arguments = []
                ) use (&$insideMutex) {
                    self::assertSame(5, $orderId);
                    $insideMutex = true;
                    try {
                        return $callback(...$arguments);
                    } finally {
                        $insideMutex = false;
                    }
                }
            );

        return $orderMutex;
    }

    private function refundAdapter(): RefundAdapter
    {
        return $this->getMockBuilder(RefundAdapter::class)
            ->disableOriginalConstructor()
            ->getMock();
    }

    private function creditmemoService(): CreditmemoService
    {
        return $this->getMockBuilder(CreditmemoService::class)
            ->disableOriginalConstructor()
            ->getMock();
    }
}
