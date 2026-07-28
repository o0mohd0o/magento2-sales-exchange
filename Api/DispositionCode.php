<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Api;

/**
 * Stable warehouse disposition decisions.
 *
 * No inventory mutation is performed in Phase 2.
 *
 * @api
 */
abstract class DispositionCode
{
    public const RESTOCK = 'restock';
    public const QUARANTINE = 'quarantine';
    public const WRITE_OFF = 'write_off';
    public const RETURN_TO_VENDOR = 'return_to_vendor';

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::RESTOCK,
            self::QUARANTINE,
            self::WRITE_OFF,
            self::RETURN_TO_VENDOR,
        ];
    }
}
