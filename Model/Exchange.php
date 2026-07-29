<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model;

use Bonlineco\SalesExchange\Api\Data\ExchangeInterface;
use Bonlineco\SalesExchange\Model\ResourceModel\Exchange as ExchangeResource;
use Magento\Framework\Model\AbstractModel;

/**
 * Exchange case persistence model.
 */
class Exchange extends AbstractModel implements ExchangeInterface
{
    /**
     * @inheritdoc
     */
    protected function _construct(): void
    {
        $this->_init(ExchangeResource::class);
    }

    public function getEntityId(): ?int
    {
        $value = $this->getData(self::ENTITY_ID);
        return $value === null ? null : (int)$value;
    }

    public function setEntityId($entityId): ExchangeInterface
    {
        return $this->setData(self::ENTITY_ID, $entityId);
    }

    public function getIncrementId(): string
    {
        return (string)$this->getData(self::INCREMENT_ID);
    }

    public function setIncrementId(string $incrementId): ExchangeInterface
    {
        return $this->setData(self::INCREMENT_ID, $incrementId);
    }

    public function getOriginalOrderId(): int
    {
        return (int)$this->getData(self::ORIGINAL_ORDER_ID);
    }

    public function setOriginalOrderId(int $orderId): ExchangeInterface
    {
        return $this->setData(self::ORIGINAL_ORDER_ID, $orderId);
    }

    public function getStoreId(): ?int
    {
        $value = $this->getData(self::STORE_ID);
        return $value === null ? null : (int)$value;
    }

    public function setStoreId(?int $storeId): ExchangeInterface
    {
        return $this->setData(self::STORE_ID, $storeId);
    }

    public function getCustomerId(): ?int
    {
        $value = $this->getData(self::CUSTOMER_ID);
        return $value === null ? null : (int)$value;
    }

    public function setCustomerId(?int $customerId): ExchangeInterface
    {
        return $this->setData(self::CUSTOMER_ID, $customerId);
    }

    public function getCurrencyCode(): string
    {
        return (string)$this->getData(self::CURRENCY_CODE);
    }

    public function setCurrencyCode(string $currencyCode): ExchangeInterface
    {
        return $this->setData(self::CURRENCY_CODE, $currencyCode);
    }

    public function getBaseCurrencyCode(): string
    {
        return (string)$this->getData(self::BASE_CURRENCY_CODE);
    }

    public function setBaseCurrencyCode(string $currencyCode): ExchangeInterface
    {
        return $this->setData(self::BASE_CURRENCY_CODE, $currencyCode);
    }

    public function getCatalogPricesIncludeTax(): ?bool
    {
        $value = $this->getData(self::CATALOG_PRICES_INCLUDE_TAX);

        return $value === null ? null : (bool)$value;
    }

    public function setCatalogPricesIncludeTax(
        ?bool $includeTax
    ): ExchangeInterface {
        return $this->setData(
            self::CATALOG_PRICES_INCLUDE_TAX,
            $includeTax
        );
    }

    public function getExchangeStatus(): string
    {
        return (string)$this->getData(self::EXCHANGE_STATUS);
    }

    public function setExchangeStatus(string $status): ExchangeInterface
    {
        return $this->setData(self::EXCHANGE_STATUS, $status);
    }

    public function getReturnStatus(): string
    {
        return (string)$this->getData(self::RETURN_STATUS);
    }

    public function setReturnStatus(string $status): ExchangeInterface
    {
        return $this->setData(self::RETURN_STATUS, $status);
    }

    public function getReplacementStatus(): string
    {
        return (string)$this->getData(self::REPLACEMENT_STATUS);
    }

    public function setReplacementStatus(string $status): ExchangeInterface
    {
        return $this->setData(self::REPLACEMENT_STATUS, $status);
    }

    public function getSettlementStatus(): string
    {
        return (string)$this->getData(self::SETTLEMENT_STATUS);
    }

    public function setSettlementStatus(string $status): ExchangeInterface
    {
        return $this->setData(self::SETTLEMENT_STATUS, $status);
    }

    public function getReturnCreditAmount(): string
    {
        return (string)$this->getData(self::RETURN_CREDIT_AMOUNT);
    }

    public function setReturnCreditAmount(string $amount): ExchangeInterface
    {
        return $this->setData(self::RETURN_CREDIT_AMOUNT, $amount);
    }

    public function getNativeReturnCreditAmount(): string
    {
        return (string)$this->getData(self::NATIVE_RETURN_CREDIT_AMOUNT);
    }

    public function setNativeReturnCreditAmount(string $amount): ExchangeInterface
    {
        return $this->setData(self::NATIVE_RETURN_CREDIT_AMOUNT, $amount);
    }

    public function getBaseNativeReturnCreditAmount(): string
    {
        return (string)$this->getData(self::BASE_NATIVE_RETURN_CREDIT_AMOUNT);
    }

    public function setBaseNativeReturnCreditAmount(string $amount): ExchangeInterface
    {
        return $this->setData(self::BASE_NATIVE_RETURN_CREDIT_AMOUNT, $amount);
    }

    public function getNativeReplacementAmount(): string
    {
        return (string)$this->getData(self::NATIVE_REPLACEMENT_AMOUNT);
    }

    public function setNativeReplacementAmount(string $amount): ExchangeInterface
    {
        return $this->setData(self::NATIVE_REPLACEMENT_AMOUNT, $amount);
    }

    public function getBaseNativeReplacementAmount(): string
    {
        return (string)$this->getData(self::BASE_NATIVE_REPLACEMENT_AMOUNT);
    }

    public function setBaseNativeReplacementAmount(string $amount): ExchangeInterface
    {
        return $this->setData(self::BASE_NATIVE_REPLACEMENT_AMOUNT, $amount);
    }

    public function getReplacementAmount(): string
    {
        return (string)$this->getData(self::REPLACEMENT_AMOUNT);
    }

    public function setReplacementAmount(string $amount): ExchangeInterface
    {
        return $this->setData(self::REPLACEMENT_AMOUNT, $amount);
    }

    public function getShippingAmount(): string
    {
        return (string)$this->getData(self::SHIPPING_AMOUNT);
    }

    public function setShippingAmount(string $amount): ExchangeInterface
    {
        return $this->setData(self::SHIPPING_AMOUNT, $amount);
    }

    public function getFeeAmount(): string
    {
        return (string)$this->getData(self::FEE_AMOUNT);
    }

    public function setFeeAmount(string $amount): ExchangeInterface
    {
        return $this->setData(self::FEE_AMOUNT, $amount);
    }

    public function getBalanceAmount(): string
    {
        return (string)$this->getData(self::BALANCE_AMOUNT);
    }

    public function setBalanceAmount(string $amount): ExchangeInterface
    {
        return $this->setData(self::BALANCE_AMOUNT, $amount);
    }

    public function getCustomerNote(): ?string
    {
        $value = $this->getData(self::CUSTOMER_NOTE);
        return $value === null ? null : (string)$value;
    }

    public function setCustomerNote(?string $note): ExchangeInterface
    {
        return $this->setData(self::CUSTOMER_NOTE, $note);
    }

    public function getInternalNote(): ?string
    {
        $value = $this->getData(self::INTERNAL_NOTE);
        return $value === null ? null : (string)$value;
    }

    public function setInternalNote(?string $note): ExchangeInterface
    {
        return $this->setData(self::INTERNAL_NOTE, $note);
    }

    public function getVersion(): int
    {
        return (int)$this->getData(self::VERSION);
    }

    public function setVersion(int $version): ExchangeInterface
    {
        return $this->setData(self::VERSION, $version);
    }

    public function getCreatedAt(): ?string
    {
        $value = $this->getData(self::CREATED_AT);
        return $value === null ? null : (string)$value;
    }

    public function setCreatedAt(?string $createdAt): ExchangeInterface
    {
        return $this->setData(self::CREATED_AT, $createdAt);
    }

    public function getUpdatedAt(): ?string
    {
        $value = $this->getData(self::UPDATED_AT);
        return $value === null ? null : (string)$value;
    }

    public function setUpdatedAt(?string $updatedAt): ExchangeInterface
    {
        return $this->setData(self::UPDATED_AT, $updatedAt);
    }

}
