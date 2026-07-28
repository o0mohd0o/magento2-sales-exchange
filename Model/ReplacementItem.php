<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model;

use Bonlineco\SalesExchange\Api\Data\ReplacementItemInterface;
use Bonlineco\SalesExchange\Model\ResourceModel\ReplacementItem as ReplacementItemResource;
use Magento\Framework\Model\AbstractModel;

/**
 * Replacement item persistence model.
 */
class ReplacementItem extends AbstractModel implements ReplacementItemInterface
{
    protected function _construct(): void
    {
        $this->_init(ReplacementItemResource::class);
    }

    public function getEntityId(): ?int
    {
        $value = $this->getData(self::ENTITY_ID);
        return $value === null ? null : (int)$value;
    }

    public function setEntityId($entityId): ReplacementItemInterface
    {
        return $this->setData(self::ENTITY_ID, $entityId);
    }

    public function getExchangeId(): int
    {
        return (int)$this->getData(self::EXCHANGE_ID);
    }

    public function setExchangeId(int $exchangeId): ReplacementItemInterface
    {
        return $this->setData(self::EXCHANGE_ID, $exchangeId);
    }

    public function getProductId(): ?int
    {
        $value = $this->getData(self::PRODUCT_ID);
        return $value === null ? null : (int)$value;
    }

    public function setProductId(?int $productId): ReplacementItemInterface
    {
        return $this->setData(self::PRODUCT_ID, $productId);
    }

    public function getSku(): string
    {
        return (string)$this->getData(self::SKU);
    }

    public function setSku(string $sku): ReplacementItemInterface
    {
        return $this->setData(self::SKU, $sku);
    }

    public function getName(): string
    {
        return (string)$this->getData(self::NAME);
    }

    public function setName(string $name): ReplacementItemInterface
    {
        return $this->setData(self::NAME, $name);
    }

    public function getQty(): string
    {
        return (string)$this->getData(self::QTY);
    }

    public function setQty(string $quantity): ReplacementItemInterface
    {
        return $this->setData(self::QTY, $quantity);
    }

    public function getUnitPriceAmount(): string
    {
        return (string)$this->getData(self::UNIT_PRICE_AMOUNT);
    }

    public function setUnitPriceAmount(string $amount): ReplacementItemInterface
    {
        return $this->setData(self::UNIT_PRICE_AMOUNT, $amount);
    }

    public function getRowTotalAmount(): string
    {
        return (string)$this->getData(self::ROW_TOTAL_AMOUNT);
    }

    public function setRowTotalAmount(string $amount): ReplacementItemInterface
    {
        return $this->setData(self::ROW_TOTAL_AMOUNT, $amount);
    }

    public function getProductOptionsJson(): ?string
    {
        $value = $this->getData(self::PRODUCT_OPTIONS_JSON);
        return $value === null ? null : (string)$value;
    }

    public function setProductOptionsJson(?string $optionsJson): ReplacementItemInterface
    {
        return $this->setData(self::PRODUCT_OPTIONS_JSON, $optionsJson);
    }

    public function getReplacementOrderItemId(): ?int
    {
        $value = $this->getData(self::REPLACEMENT_ORDER_ITEM_ID);
        return $value === null ? null : (int)$value;
    }

    public function setReplacementOrderItemId(?int $orderItemId): ReplacementItemInterface
    {
        return $this->setData(self::REPLACEMENT_ORDER_ITEM_ID, $orderItemId);
    }

    public function getVersion(): int
    {
        return (int)$this->getData(self::VERSION);
    }

    public function setVersion(int $version): ReplacementItemInterface
    {
        return $this->setData(self::VERSION, $version);
    }

    public function getCreatedAt(): ?string
    {
        $value = $this->getData(self::CREATED_AT);
        return $value === null ? null : (string)$value;
    }

    public function setCreatedAt(?string $createdAt): ReplacementItemInterface
    {
        return $this->setData(self::CREATED_AT, $createdAt);
    }

    public function getUpdatedAt(): ?string
    {
        $value = $this->getData(self::UPDATED_AT);
        return $value === null ? null : (string)$value;
    }

    public function setUpdatedAt(?string $updatedAt): ReplacementItemInterface
    {
        return $this->setData(self::UPDATED_AT, $updatedAt);
    }

}
