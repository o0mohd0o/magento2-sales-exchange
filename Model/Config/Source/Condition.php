<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\Config\Source;

use Bonlineco\SalesExchange\Api\ConditionCode;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Warehouse condition options.
 */
class Condition implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => ConditionCode::UNOPENED, 'label' => __('Unopened')],
            ['value' => ConditionCode::LIKE_NEW, 'label' => __('Like new')],
            ['value' => ConditionCode::OPENED, 'label' => __('Opened')],
            ['value' => ConditionCode::DAMAGED, 'label' => __('Damaged')],
            ['value' => ConditionCode::DEFECTIVE, 'label' => __('Defective')],
        ];
    }
}
