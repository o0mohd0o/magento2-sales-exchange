<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model;

use Bonlineco\SalesExchange\Api\Data\ReplacementSelectionInterface;
use Magento\Framework\DataObject;

/**
 * Replacement selection data object.
 */
class ReplacementSelection extends DataObject implements ReplacementSelectionInterface
{
    public function getSku(): string
    {
        return (string)$this->getData(self::SKU);
    }

    public function setSku(string $sku): ReplacementSelectionInterface
    {
        return $this->setData(self::SKU, $sku);
    }

    public function getQuantity(): string
    {
        return (string)$this->getData(self::QUANTITY);
    }

    public function setQuantity(string $quantity): ReplacementSelectionInterface
    {
        return $this->setData(self::QUANTITY, $quantity);
    }
}
