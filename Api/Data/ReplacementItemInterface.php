<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Api\Data;

/**
 * Selected replacement line snapshot.
 *
 * @api
 */
interface ReplacementItemInterface
{
    public const ENTITY_ID = 'entity_id';
    public const EXCHANGE_ID = 'exchange_id';
    public const PRODUCT_ID = 'product_id';
    public const SKU = 'sku';
    public const NAME = 'name';
    public const QTY = 'qty';
    public const UNIT_PRICE_AMOUNT = 'unit_price_amount';
    public const ROW_TOTAL_AMOUNT = 'row_total_amount';
    public const PRODUCT_OPTIONS_JSON = 'product_options_json';
    public const REPLACEMENT_ORDER_ITEM_ID = 'replacement_order_item_id';
    public const VERSION = 'version';
    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = 'updated_at';

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

    public function getProductId(): ?int;

    public function setProductId(?int $productId): self;

    public function getSku(): string;

    public function setSku(string $sku): self;

    public function getName(): string;

    public function setName(string $name): self;

    public function getQty(): string;

    public function setQty(string $quantity): self;

    public function getUnitPriceAmount(): string;

    public function setUnitPriceAmount(string $amount): self;

    public function getRowTotalAmount(): string;

    public function setRowTotalAmount(string $amount): self;

    public function getProductOptionsJson(): ?string;

    public function setProductOptionsJson(?string $optionsJson): self;

    public function getReplacementOrderItemId(): ?int;

    public function setReplacementOrderItemId(?int $orderItemId): self;

    public function getVersion(): int;

    public function setVersion(int $version): self;

    public function getCreatedAt(): ?string;

    public function setCreatedAt(?string $createdAt): self;

    public function getUpdatedAt(): ?string;

    public function setUpdatedAt(?string $updatedAt): self;

}
