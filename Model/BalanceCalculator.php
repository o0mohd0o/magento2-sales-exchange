<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model;

use Bonlineco\SalesExchange\Api\BalanceCalculatorInterface;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;

/**
 * Fixed-scale exchange balance calculator.
 */
class BalanceCalculator implements BalanceCalculatorInterface
{
    private DecimalMath $decimalMath;

    public function __construct(DecimalMath $decimalMath)
    {
        $this->decimalMath = $decimalMath;
    }

    /**
     * @inheritdoc
     */
    public function execute(
        string $replacementAmount,
        string $shippingAmount,
        string $feeAmount,
        string $returnCreditAmount
    ): string {
        $replacement = $this->decimalMath->assertNonNegative($replacementAmount, 'Replacement amount');
        $shipping = $this->decimalMath->assertNonNegative($shippingAmount, 'Shipping amount');
        $fee = $this->decimalMath->assertNonNegative($feeAmount, 'Fee amount');
        $returnCredit = $this->decimalMath->assertNonNegative($returnCreditAmount, 'Return credit amount');

        $charges = $this->decimalMath->add(
            $this->decimalMath->add($replacement, $shipping),
            $fee
        );

        return $this->decimalMath->subtract($charges, $returnCredit);
    }
}
