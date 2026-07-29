<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Integration\Ui\DataProvider;

use Magento\Framework\View\Element\UiComponent\DataProvider\CollectionFactory;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use Magento\Sales\Model\ResourceModel\Order\Grid\Collection as OrderGridCollection;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Verify the exchange grid extends, rather than replaces, admin grid mappings.
 *
 * @magentoAppArea adminhtml
 * @magentoAppIsolation enabled
 */
class CollectionFactoryWiringTest extends TestCase
{
    public function testNativeAndExchangeGridHandlesAreRegisteredTogether(): void
    {
        $collectionFactory = Bootstrap::getObjectManager()->get(
            CollectionFactory::class
        );

        self::assertInstanceOf(
            OrderGridCollection::class,
            $collectionFactory->getReport('sales_order_grid_data_source')
        );
        self::assertInstanceOf(
            SearchResult::class,
            $collectionFactory->getReport(
                'bonlineco_sales_exchange_listing_data_source'
            )
        );
    }
}
