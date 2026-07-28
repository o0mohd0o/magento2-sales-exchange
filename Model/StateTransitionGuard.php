<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model;

use Bonlineco\SalesExchange\Api\StateTransitionGuardInterface;
use Bonlineco\SalesExchange\Api\Status\ExchangeStatus;
use Bonlineco\SalesExchange\Api\Status\ReplacementStatus;
use Bonlineco\SalesExchange\Api\Status\ReturnStatus;
use Bonlineco\SalesExchange\Api\Status\SettlementStatus;
use Bonlineco\SalesExchange\Api\Status\StateDimension;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;

/**
 * Explicit allow-list state machine for all exchange workflow dimensions.
 */
class StateTransitionGuard implements StateTransitionGuardInterface
{
    /**
     * @var array<string, array<string, string[]>>
     */
    private array $transitions = [
        StateDimension::EXCHANGE => [
            ExchangeStatus::DRAFT => [
                ExchangeStatus::PENDING_APPROVAL,
                ExchangeStatus::CANCELLED,
            ],
            ExchangeStatus::PENDING_APPROVAL => [
                ExchangeStatus::DRAFT,
                ExchangeStatus::APPROVED,
                ExchangeStatus::CANCELLED,
            ],
            ExchangeStatus::APPROVED => [
                ExchangeStatus::IN_PROGRESS,
                ExchangeStatus::CANCELLED,
            ],
            ExchangeStatus::IN_PROGRESS => [
                ExchangeStatus::COMPLETED,
                ExchangeStatus::REJECTED,
                ExchangeStatus::CANCELLED,
            ],
            ExchangeStatus::COMPLETED => [],
            ExchangeStatus::REJECTED => [],
            ExchangeStatus::CANCELLED => [],
        ],
        StateDimension::RETURN => [
            ReturnStatus::PENDING => [
                ReturnStatus::AUTHORIZED,
                ReturnStatus::CANCELLED,
            ],
            ReturnStatus::AUTHORIZED => [
                ReturnStatus::IN_TRANSIT,
                ReturnStatus::RECEIVED,
                ReturnStatus::CANCELLED,
            ],
            ReturnStatus::IN_TRANSIT => [
                ReturnStatus::RECEIVED,
                ReturnStatus::CANCELLED,
            ],
            ReturnStatus::RECEIVED => [
                ReturnStatus::INSPECTED,
                ReturnStatus::CANCELLED,
            ],
            ReturnStatus::INSPECTED => [
                ReturnStatus::ACCEPTED,
                ReturnStatus::PARTIALLY_ACCEPTED,
                ReturnStatus::REJECTED,
            ],
            ReturnStatus::ACCEPTED => [],
            ReturnStatus::PARTIALLY_ACCEPTED => [],
            ReturnStatus::REJECTED => [],
            ReturnStatus::CANCELLED => [],
        ],
        StateDimension::REPLACEMENT => [
            ReplacementStatus::PENDING => [
                ReplacementStatus::CANCELLED,
            ],
            ReplacementStatus::READY => [],
            ReplacementStatus::ORDERED => [],
            ReplacementStatus::SHIPPED => [],
            ReplacementStatus::DELIVERED => [],
            ReplacementStatus::CANCELLED => [],
        ],
        StateDimension::SETTLEMENT => [
            SettlementStatus::PENDING => [
                SettlementStatus::CANCELLED,
            ],
            SettlementStatus::PAYMENT_DUE => [
                SettlementStatus::FAILED,
                SettlementStatus::CANCELLED,
            ],
            SettlementStatus::REFUND_DUE => [
                SettlementStatus::FAILED,
                SettlementStatus::CANCELLED,
            ],
            SettlementStatus::FAILED => [
                SettlementStatus::CANCELLED,
            ],
            SettlementStatus::BALANCED => [],
            SettlementStatus::PAYMENT_RECEIVED => [],
            SettlementStatus::REFUND_ISSUED => [],
            SettlementStatus::CANCELLED => [],
        ],
    ];

    /**
     * @inheritdoc
     */
    public function execute(string $dimension, string $fromStatus, string $toStatus): void
    {
        if (!isset($this->transitions[$dimension][$fromStatus])) {
            throw new InvariantViolationException(
                __('Unknown %1 status "%2".', $dimension, $fromStatus)
            );
        }

        if ($fromStatus === $toStatus) {
            return;
        }

        if (!in_array($toStatus, $this->transitions[$dimension][$fromStatus], true)) {
            throw new InvariantViolationException(
                __('The %1 workflow cannot move from "%2" to "%3".', $dimension, $fromStatus, $toStatus)
            );
        }
    }

    /**
     * @inheritdoc
     */
    public function executeReplacementIntentCancellation(string $fromStatus): void
    {
        if (!in_array(
            $fromStatus,
            [
                ReplacementStatus::PENDING,
                ReplacementStatus::READY,
                ReplacementStatus::CANCELLED,
            ],
            true
        )) {
            throw new InvariantViolationException(
                __(
                    'Only a pending or ready replacement intent can be cancelled; '
                    . 'an ordered replacement must follow the native order workflow.'
                )
            );
        }
    }

    /**
     * @inheritdoc
     */
    public function executeNativeReplacementCancellation(
        string $fromStatus
    ): void {
        if (!in_array(
            $fromStatus,
            [ReplacementStatus::ORDERED, ReplacementStatus::CANCELLED],
            true
        )) {
            throw new InvariantViolationException(
                __(
                    'Only an ordered native replacement can be synchronized '
                    . 'to the cancelled replacement state.'
                )
            );
        }
    }

    /**
     * @inheritdoc
     */
    public function executeNativeReplacementShipment(string $fromStatus): void
    {
        if ($fromStatus !== ReplacementStatus::ORDERED) {
            throw new InvariantViolationException(
                __(
                    'Only an ordered replacement can advance from a committed '
                    . 'full native shipment.'
                )
            );
        }
    }

    /**
     * @inheritdoc
     */
    public function executeProvenReplacementDelivery(string $fromStatus): void
    {
        if (!in_array(
            $fromStatus,
            [ReplacementStatus::SHIPPED, ReplacementStatus::DELIVERED],
            true
        )) {
            throw new InvariantViolationException(
                __(
                    'Only a shipped replacement can advance from a trusted '
                    . 'delivery proof.'
                )
            );
        }
    }

    /**
     * @inheritdoc
     */
    public function executeSettlementReconciliation(
        string $fromStatus,
        string $toStatus
    ): void {
        if ($fromStatus !== SettlementStatus::PENDING
            || !in_array(
                $toStatus,
                [
                    SettlementStatus::BALANCED,
                    SettlementStatus::PAYMENT_RECEIVED,
                    SettlementStatus::REFUND_ISSUED,
                ],
                true
            )
        ) {
            throw new InvariantViolationException(
                __(
                    'Canonical settlement reconciliation requires pending settlement '
                    . 'and a balance-derived successful terminal state.'
                )
            );
        }
    }
}
