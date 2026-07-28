<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model;

use Bonlineco\SalesExchange\Api\Data\SettlementInterface;
use Bonlineco\SalesExchange\Model\ResourceModel\Settlement as SettlementResource;
use Magento\Framework\Model\AbstractModel;

/**
 * Settlement ledger persistence model.
 */
class Settlement extends AbstractModel implements SettlementInterface
{
    protected function _construct(): void
    {
        $this->_init(SettlementResource::class);
    }

    public function getEntityId(): ?int
    {
        $value = $this->getData(self::ENTITY_ID);
        return $value === null ? null : (int)$value;
    }

    public function setEntityId($entityId): SettlementInterface
    {
        return $this->setData(self::ENTITY_ID, $entityId);
    }

    public function getExchangeId(): int
    {
        return (int)$this->getData(self::EXCHANGE_ID);
    }

    public function setExchangeId(int $exchangeId): SettlementInterface
    {
        return $this->setData(self::EXCHANGE_ID, $exchangeId);
    }

    public function getType(): string
    {
        return (string)$this->getData(self::TYPE);
    }

    public function setType(string $type): SettlementInterface
    {
        return $this->setData(self::TYPE, $type);
    }

    public function getStatus(): string
    {
        return (string)$this->getData(self::STATUS);
    }

    public function setStatus(string $status): SettlementInterface
    {
        return $this->setData(self::STATUS, $status);
    }

    public function getAmount(): string
    {
        return (string)$this->getData(self::AMOUNT);
    }

    public function setAmount(string $amount): SettlementInterface
    {
        return $this->setData(self::AMOUNT, $amount);
    }

    public function getCurrencyCode(): string
    {
        return (string)$this->getData(self::CURRENCY_CODE);
    }

    public function setCurrencyCode(string $currencyCode): SettlementInterface
    {
        return $this->setData(self::CURRENCY_CODE, $currencyCode);
    }

    public function getIdempotencyKey(): string
    {
        return (string)$this->getData(self::IDEMPOTENCY_KEY);
    }

    public function setIdempotencyKey(string $idempotencyKey): SettlementInterface
    {
        return $this->setData(self::IDEMPOTENCY_KEY, $idempotencyKey);
    }

    public function getExternalReference(): ?string
    {
        $value = $this->getData(self::EXTERNAL_REFERENCE);
        return $value === null ? null : (string)$value;
    }

    public function setExternalReference(?string $externalReference): SettlementInterface
    {
        return $this->setData(self::EXTERNAL_REFERENCE, $externalReference);
    }

    public function getComment(): ?string
    {
        $value = $this->getData(self::COMMENT);
        return $value === null ? null : (string)$value;
    }

    public function setComment(?string $comment): SettlementInterface
    {
        return $this->setData(self::COMMENT, $comment);
    }

    public function getVersion(): int
    {
        return (int)$this->getData(self::VERSION);
    }

    public function setVersion(int $version): SettlementInterface
    {
        return $this->setData(self::VERSION, $version);
    }

    public function getCreatedAt(): ?string
    {
        $value = $this->getData(self::CREATED_AT);
        return $value === null ? null : (string)$value;
    }

    public function setCreatedAt(?string $createdAt): SettlementInterface
    {
        return $this->setData(self::CREATED_AT, $createdAt);
    }

    public function getUpdatedAt(): ?string
    {
        $value = $this->getData(self::UPDATED_AT);
        return $value === null ? null : (string)$value;
    }

    public function setUpdatedAt(?string $updatedAt): SettlementInterface
    {
        return $this->setData(self::UPDATED_AT, $updatedAt);
    }

}
