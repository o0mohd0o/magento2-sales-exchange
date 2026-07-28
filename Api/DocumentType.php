<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Api;

/**
 * Native Magento sales documents that can be linked to an exchange case.
 *
 * @api
 */
abstract class DocumentType
{
    public const CREDITMEMO = 'creditmemo';
    public const ORDER = 'order';
    public const INVOICE = 'invoice';
    public const SHIPMENT = 'shipment';

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::CREDITMEMO,
            self::ORDER,
            self::INVOICE,
            self::SHIPMENT,
        ];
    }
}
