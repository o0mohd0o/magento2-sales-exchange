<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Api;

/**
 * Guard one independent exchange workflow transition.
 *
 * @api
 */
interface StateTransitionGuardInterface
{
    /**
     * @throws \Bonlineco\SalesExchange\Exception\InvariantViolationException
     */
    public function execute(string $dimension, string $fromStatus, string $toStatus): void;

    /**
     * Validate the specialized, mutex-protected replacement cancellation path.
     *
     * Generic workflow transitions deliberately continue to reject
     * READY -> CANCELLED because they cannot prove that Magento created no
     * native order.
     *
     * @throws \Bonlineco\SalesExchange\Exception\InvariantViolationException
     */
    public function executeReplacementIntentCancellation(string $fromStatus): void;

    /**
     * Validate cancellation synchronized with a committed Magento order.
     *
     * Generic transitions deliberately reject ORDERED -> CANCELLED because
     * only the native OrderService boundary can prove both writes are atomic.
     *
     * @throws \Bonlineco\SalesExchange\Exception\InvariantViolationException
     */
    public function executeNativeReplacementCancellation(string $fromStatus): void;

    /**
     * Validate a transition backed by a committed full Magento shipment.
     *
     * @throws \Bonlineco\SalesExchange\Exception\InvariantViolationException
     */
    public function executeNativeReplacementShipment(string $fromStatus): void;

    /**
     * Validate a transition backed by an adapter-owned delivery proof.
     *
     * @throws \Bonlineco\SalesExchange\Exception\InvariantViolationException
     */
    public function executeProvenReplacementDelivery(string $fromStatus): void;

    /**
     * Validate the specialized atomic invoice-and-ledger reconciliation path.
     *
     * Generic transitions reserve these successful terminal states because
     * they cannot prove the native invoice or immutable ledger.
     *
     * @throws \Bonlineco\SalesExchange\Exception\InvariantViolationException
     */
    public function executeSettlementReconciliation(
        string $fromStatus,
        string $toStatus
    ): void;
}
