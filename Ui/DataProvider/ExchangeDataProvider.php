<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Ui\DataProvider;

use Bonlineco\SalesExchange\Model\Authorization\ExchangeReadAuthorization;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\ReportingInterface;
use Magento\Framework\Api\Search\SearchCriteriaBuilder;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\Element\UiComponent\DataProvider\DataProvider;

/**
 * Exchange grid provider protected against direct MUI and export requests.
 */
class ExchangeDataProvider extends DataProvider
{
    private ExchangeReadAuthorization $readAuthorization;

    /**
     * @param string $name
     * @param string $primaryFieldName
     * @param string $requestFieldName
     * @param ReportingInterface $reporting
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param RequestInterface $request
     * @param FilterBuilder $filterBuilder
     * @param ExchangeReadAuthorization $readAuthorization
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $data
     */
    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        ReportingInterface $reporting,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        RequestInterface $request,
        FilterBuilder $filterBuilder,
        ExchangeReadAuthorization $readAuthorization,
        array $meta = [],
        array $data = []
    ) {
        parent::__construct(
            $name,
            $primaryFieldName,
            $requestFieldName,
            $reporting,
            $searchCriteriaBuilder,
            $request,
            $filterBuilder,
            $meta,
            $data
        );
        $this->readAuthorization = $readAuthorization;
    }

    /**
     * Guard direct grid rendering before returning records.
     *
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        $this->readAuthorization->assertAllowed();

        return parent::getData();
    }

    /**
     * Guard consumers that bypass getData(), including grid exports.
     */
    public function getSearchResult(): SearchResultInterface
    {
        $this->readAuthorization->assertAllowed();

        return parent::getSearchResult();
    }

    /**
     * Guard MUI/export ACL discovery before the component can be prepared.
     *
     * @return array<string, mixed>
     */
    public function getConfigData(): array
    {
        $this->readAuthorization->assertAllowed();

        return parent::getConfigData();
    }
}
