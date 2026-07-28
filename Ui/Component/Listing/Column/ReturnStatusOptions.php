<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Ui\Component\Listing\Column;

use Bonlineco\SalesExchange\Api\Status\ReturnStatus;
use Magento\Framework\Phrase;

/**
 * Physical return status options.
 */
class ReturnStatusOptions extends AbstractStatusOptions
{
    /**
     * @return array<string, Phrase>
     */
    protected function getLabels(): array
    {
        return [
            ReturnStatus::PENDING => __('Pending'),
            ReturnStatus::AUTHORIZED => __('Authorized'),
            ReturnStatus::IN_TRANSIT => __('In Transit'),
            ReturnStatus::RECEIVED => __('Received'),
            ReturnStatus::INSPECTED => __('Inspected'),
            ReturnStatus::ACCEPTED => __('Accepted'),
            ReturnStatus::PARTIALLY_ACCEPTED => __('Partially Accepted'),
            ReturnStatus::REJECTED => __('Rejected'),
            ReturnStatus::CANCELLED => __('Cancelled'),
        ];
    }
}
