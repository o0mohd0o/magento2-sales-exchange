<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Api\Data;

/**
 * Immutable link from an exchange case to a native Magento sales document.
 *
 * @api
 */
interface DocumentLinkInterface
{
    public const ENTITY_ID = 'entity_id';
    public const EXCHANGE_ID = 'exchange_id';
    public const DOCUMENT_TYPE = 'document_type';
    public const DOCUMENT_ID = 'document_id';
    public const INCREMENT_ID = 'increment_id';
    public const OPERATION_KEY = 'operation_key';
    public const ITEM_QUANTITIES_JSON = 'item_quantities_json';
    public const SNAPSHOT_HASH = 'snapshot_hash';
    public const AMOUNT = 'amount';
    public const EXPECTED_AMOUNT = 'expected_amount';
    public const BASE_AMOUNT = 'base_amount';
    public const CURRENCY_CODE = 'currency_code';
    public const BASE_CURRENCY_CODE = 'base_currency_code';
    public const DOCUMENT_STATUS = 'document_status';
    public const CREATED_AT = 'created_at';

    public function getEntityId(): ?int;

    /**
     * @param int|null $entityId
     * @return $this
     */
    public function setEntityId($entityId): self;

    public function getExchangeId(): int;

    public function setExchangeId(int $exchangeId): self;

    public function getDocumentType(): string;

    public function setDocumentType(string $documentType): self;

    public function getDocumentId(): int;

    public function setDocumentId(int $documentId): self;

    public function getIncrementId(): ?string;

    public function setIncrementId(?string $incrementId): self;

    public function getOperationKey(): string;

    public function setOperationKey(string $operationKey): self;

    public function getItemQuantitiesJson(): ?string;

    public function setItemQuantitiesJson(?string $itemQuantitiesJson): self;

    public function getSnapshotHash(): string;

    public function setSnapshotHash(string $snapshotHash): self;

    public function getAmount(): string;

    public function setAmount(string $amount): self;

    public function getExpectedAmount(): string;

    public function setExpectedAmount(string $amount): self;

    public function getBaseAmount(): string;

    public function setBaseAmount(string $amount): self;

    public function getCurrencyCode(): string;

    public function setCurrencyCode(string $currencyCode): self;

    public function getBaseCurrencyCode(): string;

    public function setBaseCurrencyCode(string $currencyCode): self;

    public function getDocumentStatus(): ?string;

    public function setDocumentStatus(?string $status): self;

    public function getCreatedAt(): ?string;

    public function setCreatedAt(?string $createdAt): self;
}
