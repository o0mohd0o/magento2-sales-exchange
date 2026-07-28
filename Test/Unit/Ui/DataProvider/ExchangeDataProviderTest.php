<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Unit\Ui\DataProvider;

use Bonlineco\SalesExchange\Model\Authorization\ExchangeReadAuthorization;
use Bonlineco\SalesExchange\Ui\DataProvider\ExchangeDataProvider;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\ReportingInterface;
use Magento\Framework\Api\Search\SearchCriteriaBuilder;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\AuthorizationException;
use PHPUnit\Framework\TestCase;

/**
 * Verify unauthorized grid requests cannot reach the search/reporting layer.
 */
class ExchangeDataProviderTest extends TestCase
{
    public function testDeniedProviderCannotReturnGridData(): void
    {
        $reporting = $this->createMock(ReportingInterface::class);
        $reporting->expects(self::never())->method('search');
        $readAuthorization = $this->createMock(ExchangeReadAuthorization::class);
        $readAuthorization->expects(self::once())
            ->method('assertAllowed')
            ->willThrowException(
                new AuthorizationException(__('Access denied.'))
            );
        $provider = new ExchangeDataProvider(
            'bonlineco_sales_exchange_listing_data_source',
            'entity_id',
            'entity_id',
            $reporting,
            $this->createMock(SearchCriteriaBuilder::class),
            $this->createMock(RequestInterface::class),
            $this->createMock(FilterBuilder::class),
            $readAuthorization
        );

        $this->expectException(AuthorizationException::class);
        $provider->getData();
    }

    public function testDeniedProviderCannotExposeConfigToDirectMuiOrExport(): void
    {
        $reporting = $this->createMock(ReportingInterface::class);
        $reporting->expects(self::never())->method('search');
        $readAuthorization = $this->createMock(ExchangeReadAuthorization::class);
        $readAuthorization->expects(self::once())
            ->method('assertAllowed')
            ->willThrowException(
                new AuthorizationException(__('Access denied.'))
            );
        $provider = new ExchangeDataProvider(
            'bonlineco_sales_exchange_listing_data_source',
            'entity_id',
            'entity_id',
            $reporting,
            $this->createMock(SearchCriteriaBuilder::class),
            $this->createMock(RequestInterface::class),
            $this->createMock(FilterBuilder::class),
            $readAuthorization
        );

        $this->expectException(AuthorizationException::class);
        $provider->getConfigData();
    }
}
