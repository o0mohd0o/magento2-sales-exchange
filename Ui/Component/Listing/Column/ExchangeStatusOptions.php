<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Ui\Component\Listing\Column;

use Bonlineco\SalesExchange\Api\Status\ExchangeStatus;
use Magento\Framework\Phrase;

/**
 * Overall exchange status options.
 */
class ExchangeStatusOptions extends AbstractStatusOptions
{
    /**
     * @return array<string, Phrase>
     */
    protected function getLabels(): array
    {
        return [
            ExchangeStatus::DRAFT => __('Draft'),
            ExchangeStatus::PENDING_APPROVAL => __('Pending Approval'),
            ExchangeStatus::APPROVED => __('Approved'),
            ExchangeStatus::IN_PROGRESS => __('In Progress'),
            ExchangeStatus::COMPLETED => __('Completed'),
            ExchangeStatus::REJECTED => __('Rejected'),
            ExchangeStatus::CANCELLED => __('Cancelled'),
        ];
    }
}
