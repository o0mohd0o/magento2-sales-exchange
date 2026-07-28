<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\Config\Source;

use Bonlineco\SalesExchange\Api\DispositionCode;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Warehouse disposition options.
 */
class Disposition implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => DispositionCode::RESTOCK, 'label' => __('Restock after document execution')],
            ['value' => DispositionCode::QUARANTINE, 'label' => __('Keep in quarantine')],
            ['value' => DispositionCode::WRITE_OFF, 'label' => __('Write off')],
            ['value' => DispositionCode::RETURN_TO_VENDOR, 'label' => __('Return to vendor')],
        ];
    }
}
