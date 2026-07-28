<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Api\Settlement;

/**
 * Settlement ledger entry statuses.
 *
 * @api
 */
abstract class EntryStatus
{
    public const PENDING = 'pending';
    public const PROCESSING = 'processing';
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::PENDING,
            self::PROCESSING,
            self::SUCCEEDED,
            self::FAILED,
            self::CANCELLED,
        ];
    }

    /**
     * @return string[]
     */
    public static function terminal(): array
    {
        return [self::SUCCEEDED, self::CANCELLED];
    }
}
