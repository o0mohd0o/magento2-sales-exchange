<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\Eligibility;

use Bonlineco\SalesExchange\Exception\InvariantViolationException;

/**
 * Pure order-level eligibility policy.
 */
class EligibilityPolicy
{
    /**
     * @param string[] $eligibleStatuses
     * @throws InvariantViolationException
     */
    public function execute(
        bool $enabled,
        string $orderStatus,
        array $eligibleStatuses,
        int $orderCreatedAt,
        int $now,
        int $windowDays,
        bool $hasReturnableQuantity
    ): void {
        if (!$enabled) {
            throw new InvariantViolationException(__('Sales exchanges are disabled for this store.'));
        }
        if (!in_array($orderStatus, $eligibleStatuses, true)) {
            throw new InvariantViolationException(
                __('The order status is not eligible for an exchange.')
            );
        }
        if ($windowDays <= 0) {
            throw new InvariantViolationException(
                __('The exchange window is not configured.')
            );
        }
        if ($orderCreatedAt <= 0 || $orderCreatedAt > $now) {
            throw new InvariantViolationException(
                __('The original order date is invalid.')
            );
        }
        if (($now - $orderCreatedAt) > ($windowDays * 86400)) {
            throw new InvariantViolationException(
                __('The order is outside the %1-day exchange window.', $windowDays)
            );
        }
        if (!$hasReturnableQuantity) {
            throw new InvariantViolationException(
                __('The order has no supported quantity available to exchange.')
            );
        }
    }
}
