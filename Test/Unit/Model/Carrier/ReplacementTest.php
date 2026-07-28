<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Unit\Model\Carrier;

use Bonlineco\SalesExchange\Model\Carrier\Replacement;
use Bonlineco\SalesExchange\Model\ReplacementOrder\ExecutionContext;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\Method;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Quote\Model\Quote\Item;
use Magento\Shipping\Model\Rate\Result;
use Magento\Shipping\Model\Rate\ResultFactory;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ReplacementTest extends TestCase
{
    private const INTENT_HASH =
        'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testCarrierReturnsOneZeroRateOnlyForTrustedQuote(): void
    {
        $context = new ExecutionContext();
        $carrier = $this->carrier($context);
        $trusted = $this->quote(41);
        $spoof = $this->quote(42);
        $trustedRequest = $this->request($trusted);
        $spoofRequest = $this->request($spoof);

        self::assertFalse($carrier->collectRates($trustedRequest));

        $context->execute(
            7,
            self::INTENT_HASH,
            static function () use (
                $context,
                $carrier,
                $trusted,
                $trustedRequest,
                $spoofRequest
            ): void {
                $context->markQuote($trusted);
                self::assertFalse($carrier->collectRates($spoofRequest));

                $result = $carrier->collectRates($trustedRequest);
                self::assertInstanceOf(Result::class, $result);
                $rates = $result->getAllRates();
                self::assertCount(1, $rates);
                self::assertSame(Replacement::CARRIER_CODE, $rates[0]->getCarrier());
                self::assertSame(Replacement::METHOD_CODE, $rates[0]->getMethod());
                self::assertSame(0.0, (float)$rates[0]->getPrice());
                self::assertSame(0.0, (float)$rates[0]->getCost());
            }
        );

        self::assertFalse($carrier->collectRates($trustedRequest));
    }

    public function testCarrierRejectsEmptyAndMixedQuoteRequests(): void
    {
        $context = new ExecutionContext();
        $carrier = $this->carrier($context);
        $trusted = $this->quote(41);
        $other = $this->quote(42);
        $empty = new RateRequest();
        $mixed = new RateRequest();
        $mixed->setAllItems([
            $this->item($trusted),
            $this->item($other),
        ]);

        $context->execute(
            7,
            self::INTENT_HASH,
            static function () use (
                $context,
                $carrier,
                $trusted,
                $empty,
                $mixed
            ): void {
                $context->markQuote($trusted);
                self::assertFalse($carrier->collectRates($empty));
                self::assertFalse($carrier->collectRates($mixed));
            }
        );
    }

    public function testDisabledCarrierRejectsTrustedQuote(): void
    {
        $context = new ExecutionContext();
        $carrier = $this->carrier($context, false);
        $trusted = $this->quote(41);
        $request = $this->request($trusted);

        $context->execute(
            7,
            self::INTENT_HASH,
            static function () use ($context, $carrier, $trusted, $request): void {
                $context->markQuote($trusted);
                self::assertFalse($carrier->collectRates($request));
            }
        );
    }

    private function carrier(
        ExecutionContext $executionContext,
        bool $active = true
    ): Replacement {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturn($active);
        $scopeConfig->method('getValue')
            ->willReturnCallback(
                static function (string $path): string {
                    return match ($path) {
                        'carriers/bonlineco_sales_exchange/title' => 'Exchange Replacement',
                        'carriers/bonlineco_sales_exchange/name' => 'Replacement Delivery',
                        default => '',
                    };
                }
            );
        $result = new Result($this->createMock(StoreManagerInterface::class));
        $resultFactory = $this->createMock(ResultFactory::class);
        $resultFactory->method('create')->willReturn($result);
        $priceCurrency = $this->createMock(PriceCurrencyInterface::class);
        $priceCurrency->method('round')
            ->willReturnCallback(static fn ($value): float => (float)$value);
        $methodFactory = $this->createMock(MethodFactory::class);
        $methodFactory->method('create')
            ->willReturn(new Method($priceCurrency));

        return new Replacement(
            $scopeConfig,
            $this->createMock(ErrorFactory::class),
            $this->createMock(LoggerInterface::class),
            $resultFactory,
            $methodFactory,
            $executionContext
        );
    }

    private function request(Quote $quote): RateRequest
    {
        $request = new RateRequest();
        $request->setAllItems([$this->item($quote)]);

        return $request;
    }

    private function item(Quote $quote): Item
    {
        $item = $this->getMockBuilder(Item::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuote'])
            ->getMock();
        $item->method('getQuote')->willReturn($quote);

        return $item;
    }

    private function quote(int $id): Quote
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $quote->setId($id);

        return $quote;
    }
}
