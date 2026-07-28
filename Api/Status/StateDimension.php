<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Api\Status;

/**
 * Independent workflow dimensions stored on an exchange case.
 *
 * @api
 */
abstract class StateDimension
{
    public const EXCHANGE = 'exchange';
    public const RETURN = 'return';
    public const REPLACEMENT = 'replacement';
    public const SETTLEMENT = 'settlement';

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::EXCHANGE,
            self::RETURN,
            self::REPLACEMENT,
            self::SETTLEMENT,
        ];
    }

}
