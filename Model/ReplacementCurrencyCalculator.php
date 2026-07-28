<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model;

use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;

/**
 * Match Magento quote currency rounding without using binary floats.
 *
 * Replacement unit prices are rounded to Magento's two-decimal currency
 * precision before multiplication, then row totals are rounded again. Values
 * remain stored at the module's four-decimal database scale.
 */
class ReplacementCurrencyCalculator
{
    private const INTERMEDIATE_SCALE = 8;
    private const CURRENCY_SCALE = 2;
    private const HALF_CENT = '0.00500000';

    private DecimalMath $moneyMath;

    private DecimalMath $quantityMath;

    public function __construct(
        DecimalMath $moneyMath,
        DecimalMath $quantityMath
    ) {
        $this->moneyMath = $moneyMath;
        $this->quantityMath = $quantityMath;
    }

    /**
     * Convert a catalog base price and freeze the order-currency unit price.
     *
     * @throws InvariantViolationException
     */
    public function convertUnit(string $baseUnitAmount, string $rate): string
    {
        $baseUnitAmount = $this->moneyMath->assertNonNegative(
            $baseUnitAmount,
            'Replacement base catalog price'
        );
        $rate = $this->quantityMath->assertNonNegative(
            $rate,
            'Base-to-order currency rate'
        );
        if ($this->quantityMath->compare($rate, '0') <= 0) {
            throw new InvariantViolationException(
                __('The base-to-order currency rate must be greater than zero.')
            );
        }

        return $this->roundCurrency(
            bcmul($baseUnitAmount, $rate, self::INTERMEDIATE_SCALE)
        );
    }

    /**
     * Freeze an already converted replacement unit price.
     */
    public function normalizeUnit(string $unitAmount): string
    {
        return $this->roundCurrency(
            $this->moneyMath->assertNonNegative(
                $unitAmount,
                'Replacement unit price'
            )
        );
    }

    /**
     * Calculate the canonical Magento quote row total.
     */
    public function execute(string $quantity, string $unitAmount): string
    {
        $quantity = $this->quantityMath->assertNonNegative(
            $quantity,
            'Replacement quantity'
        );
        $unitAmount = $this->normalizeUnit($unitAmount);

        return $this->roundCurrency(
            bcmul($unitAmount, $quantity, self::INTERMEDIATE_SCALE)
        );
    }

    private function roundCurrency(string $amount): string
    {
        $rounded = bcadd(
            $amount,
            self::HALF_CENT,
            self::INTERMEDIATE_SCALE
        );

        return $this->moneyMath->normalize(
            bcadd($rounded, '0', self::CURRENCY_SCALE)
        );
    }
}
