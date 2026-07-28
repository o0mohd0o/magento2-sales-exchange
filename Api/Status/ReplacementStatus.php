<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Api\Status;

/**
 * Replacement fulfillment workflow statuses.
 *
 * @api
 */
abstract class ReplacementStatus
{
    public const PENDING = 'pending';
    public const READY = 'ready';
    public const ORDERED = 'ordered';
    public const SHIPPED = 'shipped';
    public const DELIVERED = 'delivered';
    public const CANCELLED = 'cancelled';

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::PENDING,
            self::READY,
            self::ORDERED,
            self::SHIPPED,
            self::DELIVERED,
            self::CANCELLED,
        ];
    }

    /**
     * @return string[]
     */
    public static function terminal(): array
    {
        return [self::DELIVERED, self::CANCELLED];
    }

}
