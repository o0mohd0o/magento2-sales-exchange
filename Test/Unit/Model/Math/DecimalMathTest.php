<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Unit\Model\Math;

use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use PHPUnit\Framework\TestCase;

/**
 * Verify fixed-scale normalization preserves precision without rejecting
 * Magento's zero-padded decimal storage values.
 */
class DecimalMathTest extends TestCase
{
    public function testMagentoStorageScalePaddingIsAccepted(): void
    {
        self::assertSame('12999.0000', (new DecimalMath())->normalize('12999.000000'));
    }

    public function testSignificantFractionAtConfiguredScaleIsAccepted(): void
    {
        self::assertSame('1.2345', (new DecimalMath())->normalize('1.234500'));
    }

    public function testPaddedNegativeZeroIsCanonicalized(): void
    {
        self::assertSame('0.0000', (new DecimalMath())->normalize('-0.000000'));
    }

    public function testNonZeroFifthFractionalDigitIsRejected(): void
    {
        $this->expectException(InvariantViolationException::class);

        (new DecimalMath())->normalize('1.23456');
    }

    public function testNonZeroFifthFractionalDigitWithTrailingZeroIsRejected(): void
    {
        $this->expectException(InvariantViolationException::class);

        (new DecimalMath())->normalize('1.234560');
    }

    public function testNonZeroSixthFractionalDigitIsRejected(): void
    {
        $this->expectException(InvariantViolationException::class);

        (new DecimalMath())->normalize('12999.000001');
    }

    public function testPaddedMaximumQuantityPrecisionIsAccepted(): void
    {
        self::assertSame(
            '99999999.9999',
            (new DecimalMath(4, 12))->normalize('99999999.999900')
        );
    }

    public function testPaddedQuantityPrecisionOverflowIsRejected(): void
    {
        $this->expectException(InvariantViolationException::class);

        (new DecimalMath(4, 12))->normalize('100000000.000000');
    }

    public function testPaddedMaximumMoneyPrecisionIsAccepted(): void
    {
        self::assertSame(
            '9999999999999999.9999',
            (new DecimalMath())->normalize('9999999999999999.999900')
        );
    }

    public function testPaddedMoneyPrecisionOverflowIsRejected(): void
    {
        $this->expectException(InvariantViolationException::class);

        (new DecimalMath())->normalize('10000000000000000.000000');
    }
}
