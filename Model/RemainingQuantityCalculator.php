<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model;

use Bonlineco\SalesExchange\Api\RemainingQuantityCalculatorInterface;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;

/**
 * Enforce invoiced quantity against native refunds and active reservations.
 */
class RemainingQuantityCalculator implements RemainingQuantityCalculatorInterface
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
        string $invoicedQuantity,
        string $refundedQuantity,
        string $activeAllocatedQuantity
    ): string {
        $invoiced = $this->decimalMath->assertNonNegative(
            $invoicedQuantity,
            'Invoiced quantity'
        );
        $refunded = $this->decimalMath->assertNonNegative($refundedQuantity, 'Refunded quantity');
        $allocated = $this->decimalMath->assertNonNegative(
            $activeAllocatedQuantity,
            'Active allocated quantity'
        );

        $consumed = $this->decimalMath->add($refunded, $allocated);
        if ($this->decimalMath->compare($consumed, $invoiced) > 0) {
            throw new InvariantViolationException(
                __('Refunded and allocated quantities cannot exceed invoiced quantity.')
            );
        }

        return $this->decimalMath->subtract($invoiced, $consumed);
    }
}
