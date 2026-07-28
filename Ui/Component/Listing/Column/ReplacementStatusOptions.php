<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Ui\Component\Listing\Column;

use Bonlineco\SalesExchange\Api\Status\ReplacementStatus;
use Magento\Framework\Phrase;

/**
 * Replacement fulfillment status options.
 */
class ReplacementStatusOptions extends AbstractStatusOptions
{
    /**
     * @return array<string, Phrase>
     */
    protected function getLabels(): array
    {
        return [
            ReplacementStatus::PENDING => __('Pending'),
            ReplacementStatus::READY => __('Ready'),
            ReplacementStatus::ORDERED => __('Ordered'),
            ReplacementStatus::SHIPPED => __('Shipped'),
            ReplacementStatus::DELIVERED => __('Delivered'),
            ReplacementStatus::CANCELLED => __('Cancelled'),
        ];
    }
}
