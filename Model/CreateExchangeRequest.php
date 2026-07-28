<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model;

use Bonlineco\SalesExchange\Api\Data\CreateExchangeRequestInterface;
use Magento\Framework\DataObject;

/**
 * Draft exchange creation request data object.
 */
class CreateExchangeRequest extends DataObject implements CreateExchangeRequestInterface
{
    public function getOrderId(): int
    {
        return (int)$this->getData(self::ORDER_ID);
    }

    public function setOrderId(int $orderId): CreateExchangeRequestInterface
    {
        return $this->setData(self::ORDER_ID, $orderId);
    }

    public function getReturnItems(): array
    {
        return (array)$this->getData(self::RETURN_ITEMS);
    }

    public function setReturnItems(array $items): CreateExchangeRequestInterface
    {
        return $this->setData(self::RETURN_ITEMS, $items);
    }

    public function getReplacementItems(): array
    {
        return (array)$this->getData(self::REPLACEMENT_ITEMS);
    }

    public function setReplacementItems(array $items): CreateExchangeRequestInterface
    {
        return $this->setData(self::REPLACEMENT_ITEMS, $items);
    }

    public function getCustomerNote(): ?string
    {
        $value = $this->getData(self::CUSTOMER_NOTE);

        return $value === null ? null : (string)$value;
    }

    public function setCustomerNote(?string $note): CreateExchangeRequestInterface
    {
        return $this->setData(self::CUSTOMER_NOTE, $note);
    }

    public function getInternalNote(): ?string
    {
        $value = $this->getData(self::INTERNAL_NOTE);

        return $value === null ? null : (string)$value;
    }

    public function setInternalNote(?string $note): CreateExchangeRequestInterface
    {
        return $this->setData(self::INTERNAL_NOTE, $note);
    }

    public function getActorId(): int
    {
        return (int)$this->getData(self::ACTOR_ID);
    }

    public function setActorId(int $actorId): CreateExchangeRequestInterface
    {
        return $this->setData(self::ACTOR_ID, $actorId);
    }
}
