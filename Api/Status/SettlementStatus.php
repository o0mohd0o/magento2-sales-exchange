<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Api\Status;

/**
 * Financial settlement workflow statuses.
 *
 * @api
 */
abstract class SettlementStatus
{
    public const PENDING = 'pending';
    public const PAYMENT_DUE = 'payment_due';
    public const REFUND_DUE = 'refund_due';
    public const BALANCED = 'balanced';
    public const PAYMENT_RECEIVED = 'payment_received';
    public const REFUND_ISSUED = 'refund_issued';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::PENDING,
            self::PAYMENT_DUE,
            self::REFUND_DUE,
            self::BALANCED,
            self::PAYMENT_RECEIVED,
            self::REFUND_ISSUED,
            self::FAILED,
            self::CANCELLED,
        ];
    }

    /**
     * @return string[]
     */
    public static function terminal(): array
    {
        return [
            self::BALANCED,
            self::PAYMENT_RECEIVED,
            self::REFUND_ISSUED,
            self::CANCELLED,
        ];
    }

}
