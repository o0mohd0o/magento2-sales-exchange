<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model;

use Bonlineco\SalesExchange\Api\Data\ReplacementItemInterface;
use Bonlineco\SalesExchange\Api\Data\ReturnItemInterface;
use Bonlineco\SalesExchange\Api\FinancialRowCalculatorInterface;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;

/**
 * Derive frozen case totals from validated persisted child rows.
 */
class FinancialAggregateCalculator
{
    private DecimalMath $moneyMath;

    private DecimalMath $quantityMath;

    private FinancialRowCalculatorInterface $financialRowCalculator;

    private ReplacementCurrencyCalculator $replacementCurrencyCalculator;

    public function __construct(
        DecimalMath $moneyMath,
        DecimalMath $quantityMath,
        FinancialRowCalculatorInterface $financialRowCalculator,
        ReplacementCurrencyCalculator $replacementCurrencyCalculator
    ) {
        $this->moneyMath = $moneyMath;
        $this->quantityMath = $quantityMath;
        $this->financialRowCalculator = $financialRowCalculator;
        $this->replacementCurrencyCalculator = $replacementCurrencyCalculator;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function getReturnCredit(array $rows): string
    {
        $total = '0.0000';
        foreach ($rows as $row) {
            $calculated = $this->financialRowCalculator->execute(
                (string)($row[ReturnItemInterface::ACCEPTED_QTY] ?? ''),
                (string)($row[ReturnItemInterface::UNIT_CREDIT_AMOUNT] ?? '')
            );
            if ($this->moneyMath->compare(
                $calculated,
                (string)($row[ReturnItemInterface::ROW_CREDIT_AMOUNT] ?? '')
            ) !== 0) {
                throw new InvariantViolationException(
                    __('A persisted return row credit is inconsistent with its quantity and unit credit.')
                );
            }
            $total = $this->moneyMath->add($total, $calculated);
        }

        return $total;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function getReplacementAmount(array $rows): string
    {
        if ($rows === []) {
            throw new InvariantViolationException(
                __('A replacement cannot become ready without replacement items.')
            );
        }

        $total = '0.0000';
        foreach ($rows as $row) {
            if (trim((string)($row[ReplacementItemInterface::SKU] ?? '')) === ''
                || trim((string)($row[ReplacementItemInterface::NAME] ?? '')) === ''
            ) {
                throw new InvariantViolationException(
                    __('Every ready replacement item requires a SKU and name snapshot.')
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
            $unitAmount = $this->moneyMath->assertNonNegative(
                (string)($row[ReplacementItemInterface::UNIT_PRICE_AMOUNT] ?? ''),
                'Replacement unit price'
            );
            $canonicalUnitAmount = $this->replacementCurrencyCalculator
                ->normalizeUnit($unitAmount);
            if ($this->moneyMath->compare(
                $unitAmount,
                $canonicalUnitAmount
            ) !== 0) {
                throw new InvariantViolationException(
                    __('A persisted replacement unit price is not currency rounded.')
                );
            }
            $calculated = $this->replacementCurrencyCalculator->execute(
                $quantity,
                $canonicalUnitAmount
            );
            if ($this->moneyMath->compare(
                $calculated,
                (string)($row[ReplacementItemInterface::ROW_TOTAL_AMOUNT] ?? '')
            ) !== 0) {
                throw new InvariantViolationException(
                    __('A persisted replacement row total is inconsistent with its quantity and unit price.')
                );
            }
            $total = $this->moneyMath->add($total, $calculated);
        }

        return $total;
    }
}
