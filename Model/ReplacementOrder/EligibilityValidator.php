<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\ReplacementOrder;

use Bonlineco\SalesExchange\Api\Data\ExchangeInterface;
use Bonlineco\SalesExchange\Api\Data\ReplacementItemInterface;
use Bonlineco\SalesExchange\Api\Status\ExchangeStatus;
use Bonlineco\SalesExchange\Api\Status\ReplacementStatus;
use Bonlineco\SalesExchange\Api\Status\ReturnStatus;
use Bonlineco\SalesExchange\Api\Status\SettlementStatus;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\FinancialAggregateCalculator;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;

/**
 * Require a frozen, unfulfilled replacement before native quote work starts.
 */
class EligibilityValidator
{
    private FinancialAggregateCalculator $aggregateCalculator;

    private DecimalMath $moneyMath;

    private DecimalMath $quantityMath;

    public function __construct(
        FinancialAggregateCalculator $aggregateCalculator,
        DecimalMath $moneyMath,
        DecimalMath $quantityMath
    ) {
        $this->aggregateCalculator = $aggregateCalculator;
        $this->moneyMath = $moneyMath;
        $this->quantityMath = $quantityMath;
    }

    /**
     * @param array<int, array<string, mixed>> $replacementRows
     */
    public function execute(
        ExchangeInterface $exchange,
        array $replacementRows
    ): void {
        if ($exchange->getExchangeStatus() !== ExchangeStatus::IN_PROGRESS
            || !in_array(
                $exchange->getReturnStatus(),
                [ReturnStatus::ACCEPTED, ReturnStatus::PARTIALLY_ACCEPTED],
                true
            )
            || $exchange->getReplacementStatus() !== ReplacementStatus::READY
            || $exchange->getSettlementStatus() !== SettlementStatus::PENDING
        ) {
            throw new InvariantViolationException(
                __(
                    'A replacement order requires an in-progress exchange, '
                    . 'an accepted return, a ready replacement, and pending settlement.'
                )
            );
        }
        $this->assertSnapshot($exchange, $replacementRows);
        foreach ($replacementRows as $row) {
            if (($row[ReplacementItemInterface::REPLACEMENT_ORDER_ITEM_ID] ?? null) !== null) {
                throw new InvariantViolationException(
                    __('A ready replacement item is already linked to a native order item.')
                );
            }
        }
    }

    /**
     * Validate the immutable approved snapshot independently of lifecycle.
     *
     * This remains valid after the replacement becomes ordered and its native
     * item links are assigned, allowing deterministic crash recovery.
     *
     * @param array<int, array<string, mixed>> $replacementRows
     */
    public function assertSnapshot(
        ExchangeInterface $exchange,
        array $replacementRows
    ): void {
        if ($exchange->getEntityId() === null
            || $exchange->getOriginalOrderId() <= 0
            || $exchange->getStoreId() === null
        ) {
            throw new InvariantViolationException(
                __('The frozen exchange identity is incomplete.')
            );
        }
        if ($this->moneyMath->compare($exchange->getShippingAmount(), '0') !== 0
            || $this->moneyMath->compare($exchange->getFeeAmount(), '0') !== 0
        ) {
            throw new InvariantViolationException(
                __('Replacement shipping and fees are not supported in this release.')
            );
        }
        if ($replacementRows === []) {
            throw new InvariantViolationException(
                __('A replacement order requires at least one frozen replacement item.')
            );
        }

        $seen = [];
        foreach ($replacementRows as $row) {
            $replacementItemId = (int)($row[ReplacementItemInterface::ENTITY_ID] ?? 0);
            if ($replacementItemId <= 0
                || isset($seen[$replacementItemId])
                || (int)($row[ReplacementItemInterface::EXCHANGE_ID] ?? 0)
                    !== $exchange->getEntityId()
                || (int)($row[ReplacementItemInterface::PRODUCT_ID] ?? 0) <= 0
                || trim((string)($row[ReplacementItemInterface::SKU] ?? '')) === ''
                || trim((string)($row[ReplacementItemInterface::NAME] ?? '')) === ''
            ) {
                throw new InvariantViolationException(
                    __('A frozen replacement item has an invalid or duplicate identity.')
                );
            }
            $seen[$replacementItemId] = true;
            if (!$this->hasNoProductOptions(
                $row[ReplacementItemInterface::PRODUCT_OPTIONS_JSON] ?? null
            )) {
                throw new InvariantViolationException(
                    __('Configured and custom replacement options are not supported in this release.')
                );
            }
            $quantity = $this->quantityMath->assertNonNegative(
                (string)($row[ReplacementItemInterface::QTY] ?? ''),
                'Replacement quantity'
            );
            if ($this->quantityMath->compare($quantity, '0') <= 0) {
                throw new InvariantViolationException(
                    __('Replacement quantity must be greater than zero.')
                );
            }
        }

        $calculated = $this->aggregateCalculator->getReplacementAmount(
            $replacementRows
        );
        if ($this->moneyMath->compare(
            $calculated,
            $exchange->getReplacementAmount()
        ) !== 0) {
            throw new InvariantViolationException(
                __('The frozen replacement amount does not match its item rows.')
            );
        }
    }

    /**
     * @param mixed $value
     */
    private function hasNoProductOptions($value): bool
    {
        if ($value === null) {
            return true;
        }
        if (!is_string($value)) {
            return false;
        }

        return in_array(trim($value), ['', '[]', '{}', 'null'], true);
    }
}
