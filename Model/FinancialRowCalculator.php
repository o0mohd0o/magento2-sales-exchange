<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model;

use Bonlineco\SalesExchange\Api\FinancialRowCalculatorInterface;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;

/**
 * Fixed-scale row calculator with explicit half-up rounding.
 */
class FinancialRowCalculator implements FinancialRowCalculatorInterface
{
    private const RESULT_SCALE = 4;
    private const INTERMEDIATE_SCALE = 8;
    private const HALF_UNIT = '0.00005';

    private DecimalMath $moneyMath;

    private DecimalMath $quantityMath;

    public function __construct(DecimalMath $moneyMath, DecimalMath $quantityMath)
    {
        $this->moneyMath = $moneyMath;
        $this->quantityMath = $quantityMath;
    }

    /**
     * @inheritdoc
     */
    public function execute(string $quantity, string $unitAmount): string
    {
        $normalizedQuantity = $this->quantityMath->assertNonNegative(
            $quantity,
            'Row quantity'
        );
        $normalizedUnitAmount = $this->moneyMath->assertNonNegative(
            $unitAmount,
            'Row unit amount'
        );
        $product = bcmul(
            $normalizedQuantity,
            $normalizedUnitAmount,
            self::INTERMEDIATE_SCALE
        );
        $rounded = bcadd($product, self::HALF_UNIT, self::INTERMEDIATE_SCALE);

        return $this->moneyMath->normalize(
            bcadd($rounded, '0', self::RESULT_SCALE)
        );
    }
}
