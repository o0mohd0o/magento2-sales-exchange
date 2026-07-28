<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Api;

/**
 * Stable warehouse inspection condition codes.
 *
 * @api
 */
abstract class ConditionCode
{
    public const UNOPENED = 'unopened';
    public const LIKE_NEW = 'like_new';
    public const OPENED = 'opened';
    public const DAMAGED = 'damaged';
    public const DEFECTIVE = 'defective';

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::UNOPENED,
            self::LIKE_NEW,
            self::OPENED,
            self::DAMAGED,
            self::DEFECTIVE,
        ];
    }
}
