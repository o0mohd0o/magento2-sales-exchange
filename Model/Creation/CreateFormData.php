<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\Creation;

/**
 * Order-scoped create-form persistence key.
 */
abstract class CreateFormData
{
    private const KEY_PREFIX = 'bonlineco_sales_exchange_create_';

    public static function getKey(int $orderId): string
    {
        return self::KEY_PREFIX . max(0, $orderId);
    }
}
