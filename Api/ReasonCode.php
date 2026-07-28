<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Api;

/**
 * Stable, integration-safe return reason codes.
 *
 * @api
 */
abstract class ReasonCode
{
    public const WRONG_ITEM = 'wrong_item';
    public const DAMAGED = 'damaged';
    public const DEFECTIVE = 'defective';
    public const SIZE_OR_FIT = 'size_or_fit';
    public const CHANGED_MIND = 'changed_mind';
    public const OTHER = 'other';

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::WRONG_ITEM,
            self::DAMAGED,
            self::DEFECTIVE,
            self::SIZE_OR_FIT,
            self::CHANGED_MIND,
            self::OTHER,
        ];
    }
}
