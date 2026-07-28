<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Api\History;

/**
 * Supported audit actor categories.
 *
 * @api
 */
abstract class ActorType
{
    public const SYSTEM = 'system';
    public const ADMIN = 'admin';
    public const CUSTOMER = 'customer';
    public const INTEGRATION = 'integration';

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::SYSTEM,
            self::ADMIN,
            self::CUSTOMER,
            self::INTEGRATION,
        ];
    }

}
