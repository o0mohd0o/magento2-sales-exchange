<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Api\Data;

/**
 * Idempotent settlement ledger entry.
 *
 * @api
 */
interface SettlementInterface
{
    public const ENTITY_ID = 'entity_id';
    public const EXCHANGE_ID = 'exchange_id';
    public const TYPE = 'type';
    public const STATUS = 'status';
    public const AMOUNT = 'amount';
    public const CURRENCY_CODE = 'currency_code';
    public const IDEMPOTENCY_KEY = 'idempotency_key';
    public const EXTERNAL_REFERENCE = 'external_reference';
    public const COMMENT = 'comment';
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

    public function getType(): string;

    public function setType(string $type): self;

    public function getStatus(): string;

    public function setStatus(string $status): self;

    public function getAmount(): string;

    public function setAmount(string $amount): self;

    public function getCurrencyCode(): string;

    public function setCurrencyCode(string $currencyCode): self;

    public function getIdempotencyKey(): string;

    public function setIdempotencyKey(string $idempotencyKey): self;

    public function getExternalReference(): ?string;

    public function setExternalReference(?string $externalReference): self;

    public function getComment(): ?string;

    public function setComment(?string $comment): self;

    public function getVersion(): int;

    public function setVersion(int $version): self;

    public function getCreatedAt(): ?string;

    public function setCreatedAt(?string $createdAt): self;

    public function getUpdatedAt(): ?string;

    public function setUpdatedAt(?string $updatedAt): self;

}
