<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Ui\Component\Listing\Column;

use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Format an exchange amount in its snapshotted order currency.
 */
class Money extends Column
{
    private PriceCurrencyInterface $priceCurrency;

    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param PriceCurrencyInterface $priceCurrency
     * @param array<string, mixed> $components
     * @param array<string, mixed> $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        PriceCurrencyInterface $priceCurrency,
        array $components = [],
        array $data = []
    ) {
        $this->priceCurrency = $priceCurrency;
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * @inheritdoc
     */
    public function prepareDataSource(array $dataSource): array
    {
        $dataSource = parent::prepareDataSource($dataSource);
        if (empty($dataSource['data']['items'])) {
            return $dataSource;
        }

        $columnName = (string)$this->getData('name');
        foreach ($dataSource['data']['items'] as &$item) {
            if (!isset($item[$columnName])) {
                continue;
            }

            $item[$columnName] = $this->priceCurrency->format(
                $item[$columnName],
                false,
                PriceCurrencyInterface::DEFAULT_PRECISION,
                $item['store_id'] ?? null,
                $item['currency_code'] ?? null
            );
        }
        unset($item);

        return $dataSource;
    }
}
