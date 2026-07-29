<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\Math;

use Bonlineco\SalesExchange\Exception\InvariantViolationException;

/**
 * Strict fixed-scale decimal-string arithmetic.
 *
 * Values are validated before BCMath receives them so excess precision is
 * rejected instead of silently truncated.
 */
class DecimalMath
{
    private int $scale;

    private int $precision;

    public function __construct(int $scale = 4, int $precision = 20)
    {
        if ($scale < 0 || $precision <= $scale) {
            throw new \InvalidArgumentException('Decimal precision must be greater than its non-negative scale.');
        }

        $this->scale = $scale;
        $this->precision = $precision;
    }

    /**
     * Normalize a validated decimal string to the configured scale.
     *
     * @throws InvariantViolationException
     */
    public function normalize(string $value): string
    {
        if (!preg_match('/^-?(?:0|[1-9][0-9]*)(?:\\.([0-9]+))?$/D', $value, $matches)) {
            throw new InvariantViolationException(__('The decimal value "%1" is invalid.', $value));
        }

        $unsigned = ltrim($value, '-');
        $parts = explode('.', $unsigned, 2);
        $fraction = $parts[1] ?? '';
        $significantFractionLength = strlen(rtrim($fraction, '0'));

        if ($significantFractionLength > $this->scale) {
            throw new InvariantViolationException(
                __('The decimal value "%1" has more than %2 fractional digits.', $value, $this->scale)
            );
        }

        if (strlen($parts[0]) > $this->precision - $this->scale) {
            throw new InvariantViolationException(
                __('The decimal value "%1" exceeds the supported precision.', $value)
            );
        }

        $normalized = bcadd($value, '0', $this->scale);

        return bccomp($normalized, '0', $this->scale) === 0
            ? bcadd('0', '0', $this->scale)
            : $normalized;
    }

    /**
     * Add two validated decimal strings.
     *
     * @throws InvariantViolationException
     */
    public function add(string $left, string $right): string
    {
        return $this->normalize(
            bcadd($this->normalize($left), $this->normalize($right), $this->scale)
        );
    }

    /**
     * Subtract two validated decimal strings.
     *
     * @throws InvariantViolationException
     */
    public function subtract(string $left, string $right): string
    {
        return $this->normalize(
            bcsub($this->normalize($left), $this->normalize($right), $this->scale)
        );
    }

    /**
     * Compare two validated decimal strings.
     *
     * @return int<-1, 1>
     * @throws InvariantViolationException
     */
    public function compare(string $left, string $right): int
    {
        return bccomp($this->normalize($left), $this->normalize($right), $this->scale);
    }

    /**
     * Require a zero or positive decimal.
     *
     * @throws InvariantViolationException
     */
    public function assertNonNegative(string $value, string $label): string
    {
        $normalized = $this->normalize($value);
        if ($this->compare($normalized, '0') < 0) {
            throw new InvariantViolationException(__('%1 cannot be negative.', $label));
        }

        return $normalized;
    }
}
