<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Api\Data;

/**
 * Exchange case data contract.
 *
 * @api
 */
interface ExchangeInterface
{
    public const ENTITY_ID = 'entity_id';
    public const INCREMENT_ID = 'increment_id';
    public const ORIGINAL_ORDER_ID = 'original_order_id';
    public const STORE_ID = 'store_id';
    public const CUSTOMER_ID = 'customer_id';
    public const CURRENCY_CODE = 'currency_code';
    public const BASE_CURRENCY_CODE = 'base_currency_code';
    public const EXCHANGE_STATUS = 'exchange_status';
    public const RETURN_STATUS = 'return_status';
    public const REPLACEMENT_STATUS = 'replacement_status';
    public const SETTLEMENT_STATUS = 'settlement_status';
    public const RETURN_CREDIT_AMOUNT = 'return_credit_amount';
    public const NATIVE_RETURN_CREDIT_AMOUNT = 'native_return_credit_amount';
    public const BASE_NATIVE_RETURN_CREDIT_AMOUNT = 'base_native_return_credit_amount';
    public const NATIVE_REPLACEMENT_AMOUNT = 'native_replacement_amount';
    public const BASE_NATIVE_REPLACEMENT_AMOUNT = 'base_native_replacement_amount';
    public const REPLACEMENT_AMOUNT = 'replacement_amount';
    public const SHIPPING_AMOUNT = 'shipping_amount';
    public const FEE_AMOUNT = 'fee_amount';
    public const BALANCE_AMOUNT = 'balance_amount';
    public const CUSTOMER_NOTE = 'customer_note';
    public const INTERNAL_NOTE = 'internal_note';
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

    public function getIncrementId(): string;

    public function setIncrementId(string $incrementId): self;

    public function getOriginalOrderId(): int;

    public function setOriginalOrderId(int $orderId): self;

    public function getStoreId(): ?int;

    public function setStoreId(?int $storeId): self;

    public function getCustomerId(): ?int;

    public function setCustomerId(?int $customerId): self;

    public function getCurrencyCode(): string;

    public function setCurrencyCode(string $currencyCode): self;

    public function getBaseCurrencyCode(): string;

    public function setBaseCurrencyCode(string $currencyCode): self;

    public function getExchangeStatus(): string;

    public function setExchangeStatus(string $status): self;

    public function getReturnStatus(): string;

    public function setReturnStatus(string $status): self;

    public function getReplacementStatus(): string;

    public function setReplacementStatus(string $status): self;

    public function getSettlementStatus(): string;

    public function setSettlementStatus(string $status): self;

    public function getReturnCreditAmount(): string;

    public function setReturnCreditAmount(string $amount): self;

    public function getNativeReturnCreditAmount(): string;

    public function setNativeReturnCreditAmount(string $amount): self;

    public function getBaseNativeReturnCreditAmount(): string;

    public function setBaseNativeReturnCreditAmount(string $amount): self;

    public function getNativeReplacementAmount(): string;

    public function setNativeReplacementAmount(string $amount): self;

    public function getBaseNativeReplacementAmount(): string;

    public function setBaseNativeReplacementAmount(string $amount): self;

    public function getReplacementAmount(): string;

    public function setReplacementAmount(string $amount): self;

    public function getShippingAmount(): string;

    public function setShippingAmount(string $amount): self;

    public function getFeeAmount(): string;

    public function setFeeAmount(string $amount): self;

    public function getBalanceAmount(): string;

    public function setBalanceAmount(string $amount): self;

    public function getCustomerNote(): ?string;

    public function setCustomerNote(?string $note): self;

    public function getInternalNote(): ?string;

    public function setInternalNote(?string $note): self;

    public function getVersion(): int;

    public function setVersion(int $version): self;

    public function getCreatedAt(): ?string;

    public function setCreatedAt(?string $createdAt): self;

    public function getUpdatedAt(): ?string;

    public function setUpdatedAt(?string $updatedAt): self;

}
