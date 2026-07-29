<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Unit\Model\ReplacementOrder;

use Bonlineco\SalesExchange\Model\Payment\Replacement as ReplacementPayment;
use Bonlineco\SalesExchange\Model\ReplacementOrder\QuotePreparer;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Payment;
use PHPUnit\Framework\TestCase;

class QuotePreparerTest extends TestCase
{
    public function testPaymentIsAttachedBeforeMethodImport(): void
    {
        $preparer = (new \ReflectionClass(QuotePreparer::class))
            ->newInstanceWithoutConstructor();
        $quote = $this->createMock(Quote::class);
        $payment = $this->createMock(Payment::class);
        $isAttached = false;

        $quote->expects(self::once())
            ->method('getPayment')
            ->willReturn($payment);
        $payment->expects(self::once())
            ->method('setQuote')
            ->with($quote)
            ->willReturnCallback(
                function () use (&$isAttached, $payment): Payment {
                    $isAttached = true;

                    return $payment;
                }
            );
        $payment->expects(self::once())
            ->method('importData')
            ->with(['method' => ReplacementPayment::CODE])
            ->willReturnCallback(
                function () use (&$isAttached, $payment): Payment {
                    self::assertTrue(
                        $isAttached,
                        'The payment must reference its quote before method import.'
                    );

                    return $payment;
                }
            );

        $method = new \ReflectionMethod(
            QuotePreparer::class,
            'configurePayment'
        );
        $method->invoke($preparer, $quote);
    }
}
