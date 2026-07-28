<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Api\Data;

/**
 * Returned original-order line snapshot and inspection quantities.
 *
 * @api
 */
interface ReturnItemInterface
{
    public const ENTITY_ID = 'entity_id';
    public const EXCHANGE_ID = 'exchange_id';
    public const ORDER_ITEM_ID = 'order_item_id';
    public const SKU = 'sku';
    public const NAME = 'name';
    public const REQUESTED_QTY = 'requested_qty';
    public const ALLOCATED_QTY = 'allocated_qty';
    public const RECEIVED_QTY = 'received_qty';
    public const RECEIPT_RESOLVED = 'receipt_resolved';
    public const ACCEPTED_QTY = 'accepted_qty';
    public const CREDITED_QTY = 'credited_qty';
    public const REJECTED_QTY = 'rejected_qty';
    public const UNIT_CREDIT_AMOUNT = 'unit_credit_amount';
    public const ROW_CREDIT_AMOUNT = 'row_credit_amount';
    public const REASON_CODE = 'reason_code';
    public const CONDITION_CODE = 'condition_code';
    public const DISPOSITION = 'disposition';
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

    public function getOrderItemId(): int;

    public function setOrderItemId(int $orderItemId): self;

    public function getSku(): string;

    public function setSku(string $sku): self;

    public function getName(): string;

    public function setName(string $name): self;

    public function getRequestedQty(): string;

    public function setRequestedQty(string $quantity): self;

    public function getAllocatedQty(): string;

    public function setAllocatedQty(string $quantity): self;

    public function getReceivedQty(): string;

    public function setReceivedQty(string $quantity): self;

    public function isReceiptResolved(): bool;

    public function setReceiptResolved(bool $resolved): self;

    public function getAcceptedQty(): string;

    public function setAcceptedQty(string $quantity): self;

    public function getCreditedQty(): string;

    public function setCreditedQty(string $quantity): self;

    public function getRejectedQty(): string;

    public function setRejectedQty(string $quantity): self;

    public function getUnitCreditAmount(): string;

    public function setUnitCreditAmount(string $amount): self;

    public function getRowCreditAmount(): string;

    public function setRowCreditAmount(string $amount): self;

    public function getReasonCode(): ?string;

    public function setReasonCode(?string $reasonCode): self;

    public function getConditionCode(): ?string;

    public function setConditionCode(?string $conditionCode): self;

    public function getDisposition(): ?string;

    public function setDisposition(?string $disposition): self;

    public function getVersion(): int;

    public function setVersion(int $version): self;

    public function getCreatedAt(): ?string;

    public function setCreatedAt(?string $createdAt): self;

    public function getUpdatedAt(): ?string;

    public function setUpdatedAt(?string $updatedAt): self;

}
