<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\Settlement;

/**
 * Stable, version-independent keys for every Phase 3C durable artifact.
 */
class OperationKeys
{
    public function invoice(int $exchangeId): string
    {
        return sprintf('sales-exchange:settlement-invoice:v1:%d', $exchangeId);
    }

    public function returnCredit(int $exchangeId): string
    {
        return sprintf(
            'sales-exchange:settlement:return-credit:v1:%d',
            $exchangeId
        );
    }

    public function customerPayment(int $exchangeId): string
    {
        return sprintf(
            'sales-exchange:settlement:customer-payment:v1:%d',
            $exchangeId
        );
    }

    public function merchantRefund(int $exchangeId): string
    {
        return sprintf(
            'sales-exchange:settlement:merchant-refund:v1:%d',
            $exchangeId
        );
    }

    public function replacementOrder(int $exchangeId): string
    {
        return sprintf('sales-exchange:replacement-order:v1:%d', $exchangeId);
    }

    public function replacementShipment(int $exchangeId): string
    {
        return sprintf(
            'sales-exchange:replacement-shipment:v1:%d',
            $exchangeId
        );
    }
}
