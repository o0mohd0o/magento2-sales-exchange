<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Api\Settlement;

/**
 * Supported settlement ledger entry types.
 *
 * @api
 */
abstract class Type
{
    public const RETURN_CREDIT = 'return_credit';
    public const CUSTOMER_PAYMENT = 'customer_payment';
    public const MERCHANT_REFUND = 'merchant_refund';
    public const ADJUSTMENT = 'adjustment';

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::RETURN_CREDIT,
            self::CUSTOMER_PAYMENT,
            self::MERCHANT_REFUND,
            self::ADJUSTMENT,
        ];
    }
}
