<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Ui\Component\Listing\Column;

use Bonlineco\SalesExchange\Api\Status\SettlementStatus;
use Magento\Framework\Phrase;

/**
 * Financial settlement status options.
 */
class SettlementStatusOptions extends AbstractStatusOptions
{
    /**
     * @return array<string, Phrase>
     */
    protected function getLabels(): array
    {
        return [
            SettlementStatus::PENDING => __('Pending'),
            SettlementStatus::PAYMENT_DUE => __('Payment Due'),
            SettlementStatus::REFUND_DUE => __('Refund Due'),
            SettlementStatus::BALANCED => __('Balanced'),
            SettlementStatus::PAYMENT_RECEIVED => __('Payment Received'),
            SettlementStatus::REFUND_ISSUED => __('Refund Issued'),
            SettlementStatus::FAILED => __('Failed'),
            SettlementStatus::CANCELLED => __('Cancelled'),
        ];
    }
}
