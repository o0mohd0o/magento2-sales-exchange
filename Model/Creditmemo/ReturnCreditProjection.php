<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\Creditmemo;

use Bonlineco\SalesExchange\Api\Data\ReturnItemInterface;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\FinancialRowCalculator;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;

/**
 * Combine executed native credit with immutable uncredited line estimates.
 */
class ReturnCreditProjection
{
    private DecimalMath $moneyMath;

    private DecimalMath $quantityMath;

    private FinancialRowCalculator $financialRowCalculator;

    public function __construct(
        DecimalMath $moneyMath,
        DecimalMath $quantityMath,
        FinancialRowCalculator $financialRowCalculator
    ) {
        $this->moneyMath = $moneyMath;
        $this->quantityMath = $quantityMath;
        $this->financialRowCalculator = $financialRowCalculator;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function execute(string $nativeAmount, array $rows): string
    {
        $projected = $this->moneyMath->assertNonNegative(
            $nativeAmount,
            'Native return credit amount'
        );
        return $this->moneyMath->add($projected, $this->getOutstandingEstimate($rows));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function getOutstandingEstimate(array $rows): string
    {
        $outstandingEstimate = '0.0000';
        foreach ($rows as $row) {
            $accepted = $this->quantityMath->assertNonNegative(
                (string)($row[ReturnItemInterface::ACCEPTED_QTY] ?? ''),
                'Accepted quantity'
            );
            $credited = $this->quantityMath->assertNonNegative(
                (string)($row[ReturnItemInterface::CREDITED_QTY] ?? '0'),
                'Credited quantity'
            );
            if ($this->quantityMath->compare($credited, $accepted) > 0) {
                throw new InvariantViolationException(
                    __('Credited quantity cannot exceed accepted quantity.')
                );
            }
            $outstanding = $this->quantityMath->subtract($accepted, $credited);
            $outstandingEstimate = $this->moneyMath->add(
                $outstandingEstimate,
                $this->financialRowCalculator->execute(
                    $outstanding,
                    (string)($row[ReturnItemInterface::UNIT_CREDIT_AMOUNT] ?? '')
                )
            );
        }

        return $outstandingEstimate;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function assertFullyCredited(array $rows): void
    {
        foreach ($rows as $row) {
            if ($this->quantityMath->compare(
                (string)($row[ReturnItemInterface::CREDITED_QTY] ?? '0'),
                (string)($row[ReturnItemInterface::ACCEPTED_QTY] ?? '0')
            ) !== 0) {
                throw new InvariantViolationException(
                    __('Every accepted return quantity requires a linked native credit memo before completion.')
                );
            }
        }
    }
}
