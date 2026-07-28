<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Api\Status;

/**
 * Physical return workflow statuses.
 *
 * @api
 */
abstract class ReturnStatus
{
    public const PENDING = 'pending';
    public const AUTHORIZED = 'authorized';
    public const IN_TRANSIT = 'in_transit';
    public const RECEIVED = 'received';
    public const INSPECTED = 'inspected';
    public const ACCEPTED = 'accepted';
    public const PARTIALLY_ACCEPTED = 'partially_accepted';
    public const REJECTED = 'rejected';
    public const CANCELLED = 'cancelled';

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::PENDING,
            self::AUTHORIZED,
            self::IN_TRANSIT,
            self::RECEIVED,
            self::INSPECTED,
            self::ACCEPTED,
            self::PARTIALLY_ACCEPTED,
            self::REJECTED,
            self::CANCELLED,
        ];
    }

    /**
     * @return string[]
     */
    public static function terminal(): array
    {
        return [
            self::ACCEPTED,
            self::PARTIALLY_ACCEPTED,
            self::REJECTED,
            self::CANCELLED,
        ];
    }

}
