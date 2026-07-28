<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model;

use Bonlineco\SalesExchange\Api\Data\HistoryInterface;
use Bonlineco\SalesExchange\Model\ResourceModel\History as HistoryResource;
use Magento\Framework\Model\AbstractModel;

/**
 * Append-only audit history persistence model.
 */
class History extends AbstractModel implements HistoryInterface
{
    protected function _construct(): void
    {
        $this->_init(HistoryResource::class);
    }

    public function getEntityId(): ?int
    {
        $value = $this->getData(self::ENTITY_ID);
        return $value === null ? null : (int)$value;
    }

    public function setEntityId($entityId): HistoryInterface
    {
        return $this->setData(self::ENTITY_ID, $entityId);
    }

    public function getExchangeId(): int
    {
        return (int)$this->getData(self::EXCHANGE_ID);
    }

    public function setExchangeId(int $exchangeId): HistoryInterface
    {
        return $this->setData(self::EXCHANGE_ID, $exchangeId);
    }

    public function getAction(): string
    {
        return (string)$this->getData(self::ACTION);
    }

    public function setAction(string $action): HistoryInterface
    {
        return $this->setData(self::ACTION, $action);
    }

    public function getStatusDimension(): ?string
    {
        $value = $this->getData(self::STATUS_DIMENSION);
        return $value === null ? null : (string)$value;
    }

    public function setStatusDimension(?string $dimension): HistoryInterface
    {
        return $this->setData(self::STATUS_DIMENSION, $dimension);
    }

    public function getFromValue(): ?string
    {
        $value = $this->getData(self::FROM_VALUE);
        return $value === null ? null : (string)$value;
    }

    public function setFromValue(?string $value): HistoryInterface
    {
        return $this->setData(self::FROM_VALUE, $value);
    }

    public function getToValue(): ?string
    {
        $value = $this->getData(self::TO_VALUE);
        return $value === null ? null : (string)$value;
    }

    public function setToValue(?string $value): HistoryInterface
    {
        return $this->setData(self::TO_VALUE, $value);
    }

    public function getActorType(): string
    {
        return (string)$this->getData(self::ACTOR_TYPE);
    }

    public function setActorType(string $actorType): HistoryInterface
    {
        return $this->setData(self::ACTOR_TYPE, $actorType);
    }

    public function getActorId(): ?int
    {
        $value = $this->getData(self::ACTOR_ID);
        return $value === null ? null : (int)$value;
    }

    public function setActorId(?int $actorId): HistoryInterface
    {
        return $this->setData(self::ACTOR_ID, $actorId);
    }

    public function getComment(): ?string
    {
        $value = $this->getData(self::COMMENT);
        return $value === null ? null : (string)$value;
    }

    public function setComment(?string $comment): HistoryInterface
    {
        return $this->setData(self::COMMENT, $comment);
    }

    public function getCreatedAt(): ?string
    {
        $value = $this->getData(self::CREATED_AT);
        return $value === null ? null : (string)$value;
    }

    public function setCreatedAt(?string $createdAt): HistoryInterface
    {
        return $this->setData(self::CREATED_AT, $createdAt);
    }

}
