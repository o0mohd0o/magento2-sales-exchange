<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model;

use Bonlineco\SalesExchange\Api\Data\ReturnItemInterface;
use Bonlineco\SalesExchange\Model\ResourceModel\ReturnItem as ReturnItemResource;
use Magento\Framework\Model\AbstractModel;

/**
 * Return item persistence model.
 */
class ReturnItem extends AbstractModel implements ReturnItemInterface
{
    protected function _construct(): void
    {
        $this->_init(ReturnItemResource::class);
    }

    public function getEntityId(): ?int
    {
        $value = $this->getData(self::ENTITY_ID);
        return $value === null ? null : (int)$value;
    }

    public function setEntityId($entityId): ReturnItemInterface
    {
        return $this->setData(self::ENTITY_ID, $entityId);
    }

    public function getExchangeId(): int
    {
        return (int)$this->getData(self::EXCHANGE_ID);
    }

    public function setExchangeId(int $exchangeId): ReturnItemInterface
    {
        return $this->setData(self::EXCHANGE_ID, $exchangeId);
    }

    public function getOrderItemId(): int
    {
        return (int)$this->getData(self::ORDER_ITEM_ID);
    }

    public function setOrderItemId(int $orderItemId): ReturnItemInterface
    {
        return $this->setData(self::ORDER_ITEM_ID, $orderItemId);
    }

    public function getSku(): string
    {
        return (string)$this->getData(self::SKU);
    }

    public function setSku(string $sku): ReturnItemInterface
    {
        return $this->setData(self::SKU, $sku);
    }

    public function getName(): string
    {
        return (string)$this->getData(self::NAME);
    }

    public function setName(string $name): ReturnItemInterface
    {
        return $this->setData(self::NAME, $name);
    }

    public function getRequestedQty(): string
    {
        return (string)$this->getData(self::REQUESTED_QTY);
    }

    public function setRequestedQty(string $quantity): ReturnItemInterface
    {
        return $this->setData(self::REQUESTED_QTY, $quantity);
    }

    public function getAllocatedQty(): string
    {
        return (string)$this->getData(self::ALLOCATED_QTY);
    }

    public function setAllocatedQty(string $quantity): ReturnItemInterface
    {
        return $this->setData(self::ALLOCATED_QTY, $quantity);
    }

    public function getReceivedQty(): string
    {
        return (string)$this->getData(self::RECEIVED_QTY);
    }

    public function setReceivedQty(string $quantity): ReturnItemInterface
    {
        return $this->setData(self::RECEIVED_QTY, $quantity);
    }

    public function isReceiptResolved(): bool
    {
        return (bool)$this->getData(self::RECEIPT_RESOLVED);
    }

    public function setReceiptResolved(bool $resolved): ReturnItemInterface
    {
        return $this->setData(self::RECEIPT_RESOLVED, $resolved);
    }

    public function getAcceptedQty(): string
    {
        return (string)$this->getData(self::ACCEPTED_QTY);
    }

    public function setAcceptedQty(string $quantity): ReturnItemInterface
    {
        return $this->setData(self::ACCEPTED_QTY, $quantity);
    }

    public function getCreditedQty(): string
    {
        return (string)$this->getData(self::CREDITED_QTY);
    }

    public function setCreditedQty(string $quantity): ReturnItemInterface
    {
        return $this->setData(self::CREDITED_QTY, $quantity);
    }

    public function getRejectedQty(): string
    {
        return (string)$this->getData(self::REJECTED_QTY);
    }

    public function setRejectedQty(string $quantity): ReturnItemInterface
    {
        return $this->setData(self::REJECTED_QTY, $quantity);
    }

    public function getUnitCreditAmount(): string
    {
        return (string)$this->getData(self::UNIT_CREDIT_AMOUNT);
    }

    public function setUnitCreditAmount(string $amount): ReturnItemInterface
    {
        return $this->setData(self::UNIT_CREDIT_AMOUNT, $amount);
    }

    public function getRowCreditAmount(): string
    {
        return (string)$this->getData(self::ROW_CREDIT_AMOUNT);
    }

    public function setRowCreditAmount(string $amount): ReturnItemInterface
    {
        return $this->setData(self::ROW_CREDIT_AMOUNT, $amount);
    }

    public function getReasonCode(): ?string
    {
        $value = $this->getData(self::REASON_CODE);
        return $value === null ? null : (string)$value;
    }

    public function setReasonCode(?string $reasonCode): ReturnItemInterface
    {
        return $this->setData(self::REASON_CODE, $reasonCode);
    }

    public function getConditionCode(): ?string
    {
        $value = $this->getData(self::CONDITION_CODE);
        return $value === null ? null : (string)$value;
    }

    public function setConditionCode(?string $conditionCode): ReturnItemInterface
    {
        return $this->setData(self::CONDITION_CODE, $conditionCode);
    }

    public function getDisposition(): ?string
    {
        $value = $this->getData(self::DISPOSITION);
        return $value === null ? null : (string)$value;
    }

    public function setDisposition(?string $disposition): ReturnItemInterface
    {
        return $this->setData(self::DISPOSITION, $disposition);
    }

    public function getVersion(): int
    {
        return (int)$this->getData(self::VERSION);
    }

    public function setVersion(int $version): ReturnItemInterface
    {
        return $this->setData(self::VERSION, $version);
    }

    public function getCreatedAt(): ?string
    {
        $value = $this->getData(self::CREATED_AT);
        return $value === null ? null : (string)$value;
    }

    public function setCreatedAt(?string $createdAt): ReturnItemInterface
    {
        return $this->setData(self::CREATED_AT, $createdAt);
    }

    public function getUpdatedAt(): ?string
    {
        $value = $this->getData(self::UPDATED_AT);
        return $value === null ? null : (string)$value;
    }

    public function setUpdatedAt(?string $updatedAt): ReturnItemInterface
    {
        return $this->setData(self::UPDATED_AT, $updatedAt);
    }

}
