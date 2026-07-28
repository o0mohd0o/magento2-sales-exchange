<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Api\Data;

/**
 * Canonical draft-exchange creation request.
 *
 * @api
 */
interface CreateExchangeRequestInterface
{
    public const ORDER_ID = 'order_id';
    public const RETURN_ITEMS = 'return_items';
    public const REPLACEMENT_ITEMS = 'replacement_items';
    public const CUSTOMER_NOTE = 'customer_note';
    public const INTERNAL_NOTE = 'internal_note';
    public const ACTOR_ID = 'actor_id';

    public function getOrderId(): int;

    public function setOrderId(int $orderId): self;

    /**
     * @return \Bonlineco\SalesExchange\Api\Data\ReturnSelectionInterface[]
     */
    public function getReturnItems(): array;

    /**
     * @param \Bonlineco\SalesExchange\Api\Data\ReturnSelectionInterface[] $items
     */
    public function setReturnItems(array $items): self;

    /**
     * @return \Bonlineco\SalesExchange\Api\Data\ReplacementSelectionInterface[]
     */
    public function getReplacementItems(): array;

    /**
     * @param \Bonlineco\SalesExchange\Api\Data\ReplacementSelectionInterface[] $items
     */
    public function setReplacementItems(array $items): self;

    public function getCustomerNote(): ?string;

    public function setCustomerNote(?string $note): self;

    public function getInternalNote(): ?string;

    public function setInternalNote(?string $note): self;

    public function getActorId(): int;

    public function setActorId(int $actorId): self;
}
