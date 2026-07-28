<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Unit\Plugin;

use Bonlineco\SalesExchange\Model\Payment\Replacement;
use Bonlineco\SalesExchange\Model\ReplacementOrder\ExecutionContext;
use Bonlineco\SalesExchange\Plugin\ZeroTotalPlugin;
use Magento\Payment\Model\Checks\ZeroTotal;
use Magento\Payment\Model\MethodInterface;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\TestCase;

class ZeroTotalPluginTest extends TestCase
{
    private const INTENT_HASH =
        'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testOrdinaryAndSpoofedZeroTotalQuotesRemainRejected(): void
    {
        $context = new ExecutionContext();
        $subject = new ZeroTotal();
        $plugin = new ZeroTotalPlugin($context);
        $method = $this->method(Replacement::CODE);
        $trusted = $this->quote(41, 0.0);
        $spoof = $this->quote(42, 0.0);

        self::assertFalse(
            $plugin->afterIsApplicable(
                $subject,
                $subject->isApplicable($method, $trusted),
                $method,
                $trusted
            )
        );

        $context->execute(
            7,
            self::INTENT_HASH,
            static function () use (
                $context,
                $subject,
                $plugin,
                $method,
                $trusted,
                $spoof
            ): void {
                $context->markQuote($trusted);
                self::assertFalse(
                    $plugin->afterIsApplicable(
                        $subject,
                        $subject->isApplicable($method, $spoof),
                        $method,
                        $spoof
                    )
                );
                self::assertTrue(
                    $plugin->afterIsApplicable(
                        $subject,
                        $subject->isApplicable($method, $trusted),
                        $method,
                        $trusted
                    )
                );
            }
        );
    }

    public function testPluginNeverAllowsAnotherPaymentCode(): void
    {
        $context = new ExecutionContext();
        $subject = new ZeroTotal();
        $plugin = new ZeroTotalPlugin($context);
        $method = $this->method('checkmo');
        $trusted = $this->quote(41, 0.0);

        $context->execute(
            7,
            self::INTENT_HASH,
            static function () use (
                $context,
                $subject,
                $plugin,
                $method,
                $trusted
            ): void {
                $context->markQuote($trusted);
                self::assertFalse(
                    $plugin->afterIsApplicable(
                        $subject,
                        $subject->isApplicable($method, $trusted),
                        $method,
                        $trusted
                    )
                );
            }
        );
    }

    public function testExistingCoreApprovalIsNeverChanged(): void
    {
        $context = new ExecutionContext();
        $subject = new ZeroTotal();
        $plugin = new ZeroTotalPlugin($context);
        $method = $this->method(Replacement::CODE);
        $quote = $this->quote(41, 10.0);

        self::assertTrue(
            $plugin->afterIsApplicable(
                $subject,
                $subject->isApplicable($method, $quote),
                $method,
                $quote
            )
        );
    }

    private function method(string $code): MethodInterface
    {
        $method = $this->createMock(MethodInterface::class);
        $method->method('getCode')->willReturn($code);

        return $method;
    }

    private function quote(int $id, float $baseGrandTotal): Quote
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $quote->setId($id);
        $quote->setBaseGrandTotal($baseGrandTotal);

        return $quote;
    }
}
