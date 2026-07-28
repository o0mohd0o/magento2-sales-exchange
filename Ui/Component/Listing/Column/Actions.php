<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Ui\Component\Listing\Column;

use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Exchange grid row actions.
 */
class Actions extends Column
{
    private const VIEW_URL = 'salesexchange/exchange/view';

    /**
     * @inheritdoc
     */
    public function prepareDataSource(array $dataSource): array
    {
        $dataSource = parent::prepareDataSource($dataSource);
        if (empty($dataSource['data']['items'])) {
            return $dataSource;
        }

        foreach ($dataSource['data']['items'] as &$item) {
            if (!isset($item['entity_id'])) {
                continue;
            }

            $item[$this->getData('name')]['view'] = [
                'href' => $this->context->getUrl(
                    self::VIEW_URL,
                    ['entity_id' => (int)$item['entity_id']]
                ),
                'label' => __('View'),
            ];
        }
        unset($item);

        return $dataSource;
    }
}
