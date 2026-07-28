<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Integration\Model;

use Bonlineco\SalesExchange\Api\ReplacementDeliveryProofProviderInterface;
use Bonlineco\SalesExchange\Api\SynchronizeReplacementDeliveryInterface;
use Bonlineco\SalesExchange\Model\ReplacementOrder\NativeShipmentValidator;
use Bonlineco\SalesExchange\Model\ReplacementOrder\SynchronizeReplacementDelivery;
use Bonlineco\SalesExchange\Model\ReplacementOrder\UnavailableDeliveryProofProvider;
use Bonlineco\SalesExchange\Model\ResourceModel\DocumentLink as DocumentLinkResource;
use Bonlineco\SalesExchange\Model\ResourceModel\Exchange as ExchangeResource;
use Bonlineco\SalesExchange\Model\ResourceModel\History as HistoryResource;
use Bonlineco\SalesExchange\Model\ResourceModel\ReplacementItem as ReplacementItemResource;
use Bonlineco\SalesExchange\Model\ResourceModel\ReturnItem as ReturnItemResource;
use Bonlineco\SalesExchange\Model\ResourceModel\Settlement as SettlementResource;
use Bonlineco\SalesExchange\Plugin\OrderServicePlugin;
use Bonlineco\SalesExchange\Plugin\ShipOrderPlugin;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Interception\PluginListInterface;
use Magento\Sales\Model\Service\OrderService;
use Magento\Sales\Model\ShipOrder;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Prove the standard single-database lifecycle boundary is wired as designed.
 *
 * @magentoAppIsolation enabled
 * @magentoDbIsolation disabled
 */
class NativeLifecycleWiringTest extends TestCase
{
    public function testDeliveryProofIsUnavailableUntilAnAdapterOverridesIt(): void
    {
        $provider = Bootstrap::getObjectManager()->get(
            ReplacementDeliveryProofProviderInterface::class
        );

        self::assertInstanceOf(
            UnavailableDeliveryProofProvider::class,
            $provider
        );
    }

    public function testDeliveryCommandAndShipmentValidatorAreConstructible(): void
    {
        $objectManager = Bootstrap::getObjectManager();

        self::assertInstanceOf(
            SynchronizeReplacementDelivery::class,
            $objectManager->get(
                SynchronizeReplacementDeliveryInterface::class
            )
        );
        self::assertInstanceOf(
            NativeShipmentValidator::class,
            $objectManager->get(NativeShipmentValidator::class)
        );
    }

    public function testNativeLifecyclePluginsResolveFromGlobalDi(): void
    {
        $pluginList = Bootstrap::getObjectManager()->get(
            PluginListInterface::class
        );
        self::assertNotNull(
            $pluginList->getNext(OrderService::class, 'cancel')
        );
        self::assertNotNull(
            $pluginList->getNext(ShipOrder::class, 'execute')
        );

        self::assertInstanceOf(
            OrderServicePlugin::class,
            $pluginList->getPlugin(
                OrderService::class,
                'bonlineco_sales_exchange_native_order_cancellation'
            )
        );
        self::assertInstanceOf(
            ShipOrderPlugin::class,
            $pluginList->getPlugin(
                ShipOrder::class,
                'bonlineco_sales_exchange_native_full_shipment'
            )
        );
    }

    public function testModuleWritesShareTheSalesTransactionConnection(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $salesConnection = $objectManager->get(ResourceConnection::class)
            ->getConnection('sales');
        $resourceTypes = [
            ExchangeResource::class,
            ReturnItemResource::class,
            ReplacementItemResource::class,
            DocumentLinkResource::class,
            SettlementResource::class,
            HistoryResource::class,
        ];

        foreach ($resourceTypes as $resourceType) {
            self::assertSame(
                $salesConnection,
                $objectManager->get($resourceType)->getConnection(),
                sprintf(
                    '%s must share Magento sales transaction boundaries.',
                    $resourceType
                )
            );
        }
    }
}
