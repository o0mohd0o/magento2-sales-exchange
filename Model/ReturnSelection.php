<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model;

use Bonlineco\SalesExchange\Api\Data\ReturnSelectionInterface;
use Magento\Framework\DataObject;

/**
 * Return selection data object.
 */
class ReturnSelection extends DataObject implements ReturnSelectionInterface
{
    public function getOrderItemId(): int
    {
        return (int)$this->getData(self::ORDER_ITEM_ID);
    }

    public function setOrderItemId(int $orderItemId): ReturnSelectionInterface
    {
        return $this->setData(self::ORDER_ITEM_ID, $orderItemId);
    }

    public function getQuantity(): string
    {
        return (string)$this->getData(self::QUANTITY);
    }

    public function setQuantity(string $quantity): ReturnSelectionInterface
    {
        return $this->setData(self::QUANTITY, $quantity);
    }

    public function getReasonCode(): string
    {
        return (string)$this->getData(self::REASON_CODE);
    }

    public function setReasonCode(string $reasonCode): ReturnSelectionInterface
    {
        return $this->setData(self::REASON_CODE, $reasonCode);
    }
}
