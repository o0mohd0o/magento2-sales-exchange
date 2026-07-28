<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model;

use Bonlineco\SalesExchange\Api\Data\DocumentLinkInterface;
use Bonlineco\SalesExchange\Model\ResourceModel\DocumentLink as DocumentLinkResource;
use Magento\Framework\Model\AbstractModel;

/**
 * Native-document link persistence model.
 */
class DocumentLink extends AbstractModel implements DocumentLinkInterface
{
    protected function _construct(): void
    {
        $this->_init(DocumentLinkResource::class);
    }

    public function getEntityId(): ?int
    {
        $value = $this->getData(self::ENTITY_ID);
        return $value === null ? null : (int)$value;
    }

    public function setEntityId($entityId): DocumentLinkInterface
    {
        return $this->setData(self::ENTITY_ID, $entityId);
    }

    public function getExchangeId(): int
    {
        return (int)$this->getData(self::EXCHANGE_ID);
    }

    public function setExchangeId(int $exchangeId): DocumentLinkInterface
    {
        return $this->setData(self::EXCHANGE_ID, $exchangeId);
    }

    public function getDocumentType(): string
    {
        return (string)$this->getData(self::DOCUMENT_TYPE);
    }

    public function setDocumentType(string $documentType): DocumentLinkInterface
    {
        return $this->setData(self::DOCUMENT_TYPE, $documentType);
    }

    public function getDocumentId(): int
    {
        return (int)$this->getData(self::DOCUMENT_ID);
    }

    public function setDocumentId(int $documentId): DocumentLinkInterface
    {
        return $this->setData(self::DOCUMENT_ID, $documentId);
    }

    public function getIncrementId(): ?string
    {
        $value = $this->getData(self::INCREMENT_ID);
        return $value === null ? null : (string)$value;
    }

    public function setIncrementId(?string $incrementId): DocumentLinkInterface
    {
        return $this->setData(self::INCREMENT_ID, $incrementId);
    }

    public function getOperationKey(): string
    {
        return (string)$this->getData(self::OPERATION_KEY);
    }

    public function setOperationKey(string $operationKey): DocumentLinkInterface
    {
        return $this->setData(self::OPERATION_KEY, $operationKey);
    }

    public function getItemQuantitiesJson(): ?string
    {
        $value = $this->getData(self::ITEM_QUANTITIES_JSON);
        return $value === null ? null : (string)$value;
    }

    public function setItemQuantitiesJson(?string $itemQuantitiesJson): DocumentLinkInterface
    {
        return $this->setData(self::ITEM_QUANTITIES_JSON, $itemQuantitiesJson);
    }

    public function getSnapshotHash(): string
    {
        return (string)$this->getData(self::SNAPSHOT_HASH);
    }

    public function setSnapshotHash(string $snapshotHash): DocumentLinkInterface
    {
        return $this->setData(self::SNAPSHOT_HASH, $snapshotHash);
    }

    public function getAmount(): string
    {
        return (string)$this->getData(self::AMOUNT);
    }

    public function setAmount(string $amount): DocumentLinkInterface
    {
        return $this->setData(self::AMOUNT, $amount);
    }

    public function getExpectedAmount(): string
    {
        return (string)$this->getData(self::EXPECTED_AMOUNT);
    }

    public function setExpectedAmount(string $amount): DocumentLinkInterface
    {
        return $this->setData(self::EXPECTED_AMOUNT, $amount);
    }

    public function getBaseAmount(): string
    {
        return (string)$this->getData(self::BASE_AMOUNT);
    }

    public function setBaseAmount(string $amount): DocumentLinkInterface
    {
        return $this->setData(self::BASE_AMOUNT, $amount);
    }

    public function getCurrencyCode(): string
    {
        return (string)$this->getData(self::CURRENCY_CODE);
    }

    public function setCurrencyCode(string $currencyCode): DocumentLinkInterface
    {
        return $this->setData(self::CURRENCY_CODE, $currencyCode);
    }

    public function getBaseCurrencyCode(): string
    {
        return (string)$this->getData(self::BASE_CURRENCY_CODE);
    }

    public function setBaseCurrencyCode(string $currencyCode): DocumentLinkInterface
    {
        return $this->setData(self::BASE_CURRENCY_CODE, $currencyCode);
    }

    public function getDocumentStatus(): ?string
    {
        $value = $this->getData(self::DOCUMENT_STATUS);
        return $value === null ? null : (string)$value;
    }

    public function setDocumentStatus(?string $status): DocumentLinkInterface
    {
        return $this->setData(self::DOCUMENT_STATUS, $status);
    }

    public function getCreatedAt(): ?string
    {
        $value = $this->getData(self::CREATED_AT);
        return $value === null ? null : (string)$value;
    }

    public function setCreatedAt(?string $createdAt): DocumentLinkInterface
    {
        return $this->setData(self::CREATED_AT, $createdAt);
    }
}
