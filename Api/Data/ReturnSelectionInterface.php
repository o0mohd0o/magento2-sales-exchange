<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Api\Data;

/**
 * Selected original-order line for a draft exchange.
 *
 * @api
 */
interface ReturnSelectionInterface
{
    public const ORDER_ITEM_ID = 'order_item_id';
    public const QUANTITY = 'quantity';
    public const REASON_CODE = 'reason_code';

    public function getOrderItemId(): int;

    public function setOrderItemId(int $orderItemId): self;

    public function getQuantity(): string;

    public function setQuantity(string $quantity): self;

    public function getReasonCode(): string;

    public function setReasonCode(string $reasonCode): self;
}
