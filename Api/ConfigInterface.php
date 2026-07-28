<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Api;

/**
 * Store-scoped exchange configuration.
 *
 * @api
 */
interface ConfigInterface
{
    public function isEnabled(?int $storeId = null): bool;

    public function getExchangeWindowDays(?int $storeId = null): int;

    /**
     * @return string[]
     */
    public function getEligibleOrderStatuses(?int $storeId = null): array;

    /**
     * @return string[]
     */
    public function getAllowedReasonCodes(?int $storeId = null): array;
}
