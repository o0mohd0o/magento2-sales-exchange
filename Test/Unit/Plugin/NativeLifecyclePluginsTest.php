<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Unit\Plugin;

use Bonlineco\SalesExchange\Model\ReplacementOrder\NativeOrderCancellationSynchronizer;
use Bonlineco\SalesExchange\Model\ReplacementOrder\NativeShipmentSynchronizer;
use Bonlineco\SalesExchange\Plugin\OrderServicePlugin;
use Bonlineco\SalesExchange\Plugin\ShipOrderPlugin;
use Magento\Sales\Api\Data\ShipmentItemCreationInterface;
use Magento\Sales\Model\Service\OrderService;
use Magento\Sales\Model\ShipOrder;
use PHPUnit\Framework\TestCase;

class NativeLifecyclePluginsTest extends TestCase
{
    public function testOrderServicePluginDelegatesCancellation(): void
    {
        $synchronizer = $this->createMock(
            NativeOrderCancellationSynchronizer::class
        );
        $synchronizer->expects(self::once())->method('execute')
            ->with(
                200,
                self::callback(
                    static fn (mixed $value): bool => is_callable($value)
                )
            )
            ->willReturnCallback(
                static function (int $orderId, callable $proceed): bool {
                    return (bool)$proceed($orderId);
                }
            );
        $proceeded = false;
        $result = (new OrderServicePlugin($synchronizer))->aroundCancel(
            $this->getMockBuilder(OrderService::class)
                ->disableOriginalConstructor()
                ->getMock(),
            static function (int $orderId) use (&$proceeded): bool {
                self::assertSame(200, $orderId);
                $proceeded = true;

                return true;
            },
            200
        );

        self::assertTrue($result);
        self::assertTrue($proceeded);
    }

    public function testShipOrderPluginDelegatesEveryNativeArgument(): void
    {
        $item = $this->createMock(ShipmentItemCreationInterface::class);
        $synchronizer = $this->createMock(
            NativeShipmentSynchronizer::class
        );
        $synchronizer->expects(self::once())->method('execute')
            ->with(
                200,
                [$item],
                false,
                self::callback(
                    static fn (mixed $value): bool => is_callable($value)
                )
            )
            ->willReturnCallback(
                static function (
                    int $orderId,
                    array $items,
                    bool $notify,
                    callable $proceed
                ): int {
                    unset($orderId, $items, $notify);

                    return (int)$proceed();
                }
            );
        $result = (new ShipOrderPlugin($synchronizer))->aroundExecute(
            $this->getMockBuilder(ShipOrder::class)
                ->disableOriginalConstructor()
                ->getMock(),
            static function (
                int $orderId,
                array $items,
                bool $notify,
                bool $appendComment,
                $comment,
                array $tracks,
                array $packages,
                $arguments
            ) use ($item): int {
                self::assertSame(200, $orderId);
                self::assertSame([$item], $items);
                self::assertFalse($notify);
                self::assertTrue($appendComment);
                self::assertNull($comment);
                self::assertSame(['track'], $tracks);
                self::assertSame(['package'], $packages);
                self::assertNull($arguments);

                return 601;
            },
            200,
            [$item],
            false,
            true,
            null,
            ['track'],
            ['package'],
            null
        );

        self::assertSame(601, $result);
    }

    public function testGlobalDiRegistersOutermostLifecyclePlugins(): void
    {
        $configuration = simplexml_load_file(
            dirname(__DIR__, 3) . '/etc/di.xml'
        );
        self::assertNotFalse($configuration);
        $orderPlugins = $configuration->xpath(
            '/config/type[@name="Magento\\Sales\\Model\\Service\\OrderService"]'
            . '/plugin[@type="Bonlineco\\SalesExchange\\Plugin\\OrderServicePlugin"]'
        );
        $shipmentPlugins = $configuration->xpath(
            '/config/type[@name="Magento\\Sales\\Model\\ShipOrder"]'
            . '/plugin[@type="Bonlineco\\SalesExchange\\Plugin\\ShipOrderPlugin"]'
        );

        self::assertCount(1, $orderPlugins);
        self::assertCount(1, $shipmentPlugins);
        self::assertSame('-1000', (string)$orderPlugins[0]['sortOrder']);
        self::assertSame('-1000', (string)$shipmentPlugins[0]['sortOrder']);
    }
}
