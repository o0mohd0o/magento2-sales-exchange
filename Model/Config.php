<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model;

use Bonlineco\SalesExchange\Api\ConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Read and normalize store-scoped exchange configuration.
 */
class Config implements ConfigInterface
{
    private const XML_PATH_ENABLED = 'sales_exchange/general/enabled';
    private const XML_PATH_WINDOW_DAYS = 'sales_exchange/general/window_days';
    private const XML_PATH_ELIGIBLE_STATUSES = 'sales_exchange/general/eligible_order_statuses';
    private const XML_PATH_ALLOWED_REASONS = 'sales_exchange/general/allowed_reasons';

    private ScopeConfigInterface $scopeConfig;

    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getExchangeWindowDays(?int $storeId = null): int
    {
        return max(
            0,
            (int)$this->scopeConfig->getValue(
                self::XML_PATH_WINDOW_DAYS,
                ScopeInterface::SCOPE_STORE,
                $storeId
            )
        );
    }

    public function getEligibleOrderStatuses(?int $storeId = null): array
    {
        return $this->getCsvValue(self::XML_PATH_ELIGIBLE_STATUSES, $storeId);
    }

    public function getAllowedReasonCodes(?int $storeId = null): array
    {
        return $this->getCsvValue(self::XML_PATH_ALLOWED_REASONS, $storeId);
    }

    /**
     * @return string[]
     */
    private function getCsvValue(string $path, ?int $storeId): array
    {
        $value = (string)$this->scopeConfig->getValue(
            $path,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $values = array_map('trim', explode(',', $value));

        return array_values(array_unique(array_filter($values, 'strlen')));
    }
}
