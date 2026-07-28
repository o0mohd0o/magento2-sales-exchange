<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model;

use Bonlineco\SalesExchange\Api\AllocationValidatorInterface;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;

/**
 * Strict positive allocation guard.
 */
class AllocationValidator implements AllocationValidatorInterface
{
    private DecimalMath $decimalMath;

    public function __construct(DecimalMath $decimalMath)
    {
        $this->decimalMath = $decimalMath;
    }

    /**
     * @inheritdoc
     */
    public function execute(string $requestedQuantity, string $remainingQuantity): void
    {
        $requested = $this->decimalMath->assertNonNegative($requestedQuantity, 'Requested quantity');
        $remaining = $this->decimalMath->assertNonNegative($remainingQuantity, 'Remaining quantity');

        if ($this->decimalMath->compare($requested, '0') <= 0) {
            throw new InvariantViolationException(__('Requested quantity must be greater than zero.'));
        }
        if ($this->decimalMath->compare($requested, $remaining) > 0) {
            throw new InvariantViolationException(
                __('Requested quantity cannot exceed the remaining exchangeable quantity.')
            );
        }
    }
}
