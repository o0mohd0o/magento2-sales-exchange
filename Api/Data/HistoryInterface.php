<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Api\Data;

/**
 * Immutable exchange audit record.
 *
 * @api
 */
interface HistoryInterface
{
    public const ENTITY_ID = 'entity_id';
    public const EXCHANGE_ID = 'exchange_id';
    public const ACTION = 'action';
    public const STATUS_DIMENSION = 'status_dimension';
    public const FROM_VALUE = 'from_value';
    public const TO_VALUE = 'to_value';
    public const ACTOR_TYPE = 'actor_type';
    public const ACTOR_ID = 'actor_id';
    public const COMMENT = 'comment';
    public const CREATED_AT = 'created_at';

    public function getEntityId(): ?int;

    /**
     * Set the entity ID.
     *
     * The parameter is intentionally untyped to remain compatible with
     * Magento\Framework\Model\AbstractModel::setEntityId().
     *
     * @param int|null $entityId
     * @return $this
     */
    public function setEntityId($entityId): self;

    public function getExchangeId(): int;

    public function setExchangeId(int $exchangeId): self;

    public function getAction(): string;

    public function setAction(string $action): self;

    public function getStatusDimension(): ?string;

    public function setStatusDimension(?string $dimension): self;

    public function getFromValue(): ?string;

    public function setFromValue(?string $value): self;

    public function getToValue(): ?string;

    public function setToValue(?string $value): self;

    public function getActorType(): string;

    public function setActorType(string $actorType): self;

    public function getActorId(): ?int;

    public function setActorId(?int $actorId): self;

    public function getComment(): ?string;

    public function setComment(?string $comment): self;

    public function getCreatedAt(): ?string;

    public function setCreatedAt(?string $createdAt): self;

}
