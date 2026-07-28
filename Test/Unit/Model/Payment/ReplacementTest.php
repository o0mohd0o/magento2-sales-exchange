<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Unit\Model\Payment;

use Bonlineco\SalesExchange\Model\Payment\Replacement;
use Bonlineco\SalesExchange\Model\ReplacementOrder\ExecutionContext;
use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Model\ActionValidator\RemoveAction;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Payment\Helper\Data as PaymentData;
use Magento\Payment\Model\Method\Logger;
use Magento\Quote\Api\Data\CartInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ReplacementTest extends TestCase
{
    private const INTENT_HASH =
        'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testMethodIsUnavailableToOrdinaryAndSpoofedQuotes(): void
    {
        $context = new ExecutionContext();
        $method = $this->method($context);
        $trusted = $this->quote(41);
        $spoof = $this->quote(42);

        self::assertFalse($method->isAvailable($trusted));
        self::assertFalse($method->isAvailable(null));

        $context->execute(
            7,
            self::INTENT_HASH,
            static function () use ($context, $method, $trusted, $spoof): void {
                $context->markQuote($trusted);
                self::assertFalse($method->isAvailable($spoof));
                self::assertTrue($method->isAvailable($trusted));
            }
        );

        self::assertFalse($method->isAvailable($trusted));
    }

    public function testDisabledConfigurationStillBlocksTrustedQuote(): void
    {
        $context = new ExecutionContext();
        $method = $this->method($context, false);
        $trusted = $this->quote(41);

        $context->execute(
            7,
            self::INTENT_HASH,
            static function () use ($context, $method, $trusted): void {
                $context->markQuote($trusted);
                self::assertFalse($method->isAvailable($trusted));
            }
        );
    }

    private function method(
        ExecutionContext $executionContext,
        bool $active = true
    ): Replacement {
        $eventManager = $this->createMock(ManagerInterface::class);
        $context = new Context(
            $this->createMock(LoggerInterface::class),
            $eventManager,
            $this->createMock(CacheInterface::class),
            $this->createMock(State::class),
            $this->createMock(RemoveAction::class)
        );
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->willReturnCallback(
                static fn (string $path): int =>
                    $path === 'payment/bonlineco_sales_exchange/active'
                        ? (int)$active
                        : 0
            );

        return new Replacement(
            $context,
            new Registry(),
            $this->createMock(ExtensionAttributesFactory::class),
            $this->createMock(AttributeValueFactory::class),
            $this->createMock(PaymentData::class),
            $scopeConfig,
            $this->createMock(Logger::class),
            $executionContext,
            null,
            null,
            [],
            $this->createMock(DirectoryHelper::class)
        );
    }

    private function quote(int $id): CartInterface
    {
        $quote = $this->createMock(CartInterface::class);
        $quote->method('getId')->willReturn($id);
        $quote->method('getStoreId')->willReturn(1);

        return $quote;
    }
}
