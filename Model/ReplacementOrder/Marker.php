<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\ReplacementOrder;

/**
 * Durable native quote and order marker field names.
 */
abstract class Marker
{
    public const EXCHANGE_ID = 'bonlineco_exchange_id';
    public const INTENT_HASH = 'bonlineco_exchange_intent_hash';
    public const REPLACEMENT_ITEM_ID = 'bonlineco_exchange_replacement_item_id';
}
