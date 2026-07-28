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
 * Enforce quantity conservation for a return line.
 */
class ReturnItemQuantityValidator
{
    private DecimalMath $decimalMath;

    public function __construct(DecimalMath $decimalMath)
    {
        $this->decimalMath = $decimalMath;
    }

    /**
     * Validate the line at any non-terminal workflow stage.
     *
     * @throws InvariantViolationException
     */
    public function execute(
        string $requestedQuantity,
        string $allocatedQuantity,
        string $receivedQuantity,
        string $acceptedQuantity,
        string $rejectedQuantity,
        string $creditedQuantity = '0'
    ): void {
        $requested = $this->decimalMath->assertNonNegative($requestedQuantity, 'Requested quantity');
        $allocated = $this->decimalMath->assertNonNegative($allocatedQuantity, 'Allocated quantity');
        $received = $this->decimalMath->assertNonNegative($receivedQuantity, 'Received quantity');
        $accepted = $this->decimalMath->assertNonNegative($acceptedQuantity, 'Accepted quantity');
        $rejected = $this->decimalMath->assertNonNegative($rejectedQuantity, 'Rejected quantity');
        $credited = $this->decimalMath->assertNonNegative($creditedQuantity, 'Credited quantity');

        if ($this->decimalMath->compare($requested, '0') <= 0) {
            throw new InvariantViolationException(__('Requested quantity must be greater than zero.'));
        }
        if ($this->decimalMath->compare($allocated, $requested) > 0) {
            throw new InvariantViolationException(__('Allocated quantity cannot exceed requested quantity.'));
        }
        if ($this->decimalMath->compare($received, $allocated) > 0) {
            throw new InvariantViolationException(__('Received quantity cannot exceed allocated quantity.'));
        }

        $inspected = $this->decimalMath->add($accepted, $rejected);
        if ($this->decimalMath->compare($inspected, $received) > 0) {
            throw new InvariantViolationException(
                __('Accepted and rejected quantities cannot exceed received quantity.')
            );
        }
        if ($this->decimalMath->compare($credited, $accepted) > 0) {
            throw new InvariantViolationException(
                __('Credited quantity cannot exceed accepted quantity.')
            );
        }
    }

    /**
     * Require every received unit to have an inspection outcome.
     *
     * @throws InvariantViolationException
     */
    public function assertFullyInspected(
        string $receivedQuantity,
        string $acceptedQuantity,
        string $rejectedQuantity
    ): void {
        $received = $this->decimalMath->assertNonNegative($receivedQuantity, 'Received quantity');
        $accepted = $this->decimalMath->assertNonNegative($acceptedQuantity, 'Accepted quantity');
        $rejected = $this->decimalMath->assertNonNegative($rejectedQuantity, 'Rejected quantity');
        $inspected = $this->decimalMath->add($accepted, $rejected);

        if ($this->decimalMath->compare($inspected, $received) !== 0) {
            throw new InvariantViolationException(
                __('Every received unit must be either accepted or rejected before the return is closed.')
            );
        }
    }
}
