<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Unit\Model\Creditmemo;

use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Creditmemo\ExecutionContext;
use Bonlineco\SalesExchange\Plugin\CreditmemoDocumentFactoryPlugin;
use Bonlineco\SalesExchange\Test\Unit\Fixture\CreditmemoCreationArgumentsExtensionStubInterface;
use Magento\Sales\Api\Data\CreditmemoCreationArgumentsInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\CreditmemoDocumentFactory;
use PHPUnit\Framework\TestCase;

class ExecutionContextTest extends TestCase
{
    private const KEY = 'creditmemo:exchange:7:version:4';

    public function testMatchingActiveContextPropagatesTransientMarker(): void
    {
        $context = new ExecutionContext();
        $creditmemo = $this->creditmemo();
        $plugin = new CreditmemoDocumentFactoryPlugin($context);

        $context->execute(
            self::KEY,
            function () use ($plugin, $creditmemo): void {
                $plugin->afterCreateFromOrder(
                    $this->factory(),
                    $creditmemo,
                    $this->createMock(OrderInterface::class),
                    [],
                    null,
                    false,
                    $this->arguments(self::KEY)
                );
            }
        );

        self::assertSame(self::KEY, $context->readCreditmemoMarker($creditmemo));
    }

    public function testSpoofedMarkerWithoutActiveContextIsIgnored(): void
    {
        $context = new ExecutionContext();
        $creditmemo = $this->creditmemo();
        (new CreditmemoDocumentFactoryPlugin($context))->afterCreateFromOrder(
            $this->factory(),
            $creditmemo,
            $this->createMock(OrderInterface::class),
            [],
            null,
            false,
            $this->arguments(self::KEY)
        );

        self::assertNull($context->readCreditmemoMarker($creditmemo));
    }

    public function testContextIsClearedWhenNativeExecutionThrows(): void
    {
        $context = new ExecutionContext();
        try {
            $context->execute(
                self::KEY,
                static function (): void {
                    throw new \RuntimeException('native failure');
                }
            );
            self::fail('The test callback must throw.');
        } catch (\RuntimeException $exception) {
            self::assertSame('native failure', $exception->getMessage());
        }

        self::assertFalse($context->isActiveFor(self::KEY));
    }

    public function testInvalidOperationKeyCannotActivateContext(): void
    {
        $this->expectException(InvariantViolationException::class);
        (new ExecutionContext())->execute('caller-controlled', static fn (): bool => true);
    }

    public function testTrustedRefundRequiresCurrentMarkerAndRefundedIdentity(): void
    {
        $context = new ExecutionContext();
        $creditmemo = $this->creditmemo();
        $creditmemo->setEntityId(91)
            ->setIncrementId('000000091')
            ->setState(Creditmemo::STATE_REFUNDED);

        $context->execute(
            self::KEY,
            function () use ($context, $creditmemo): void {
                $this->propagateMarker($context, $creditmemo);
                $context->assertTrustedRefund($creditmemo, 91, self::KEY);
            }
        );

        self::assertTrue(true);
    }

    public function testTrustedRefundRejectsOldUnmarkedDocument(): void
    {
        $context = new ExecutionContext();
        $creditmemo = $this->creditmemo();
        $creditmemo->setEntityId(90)
            ->setIncrementId('000000090')
            ->setState(Creditmemo::STATE_REFUNDED);
        $this->expectException(InvariantViolationException::class);

        $context->execute(
            self::KEY,
            static function () use ($context, $creditmemo): void {
                $context->assertTrustedRefund($creditmemo, 91, self::KEY);
            }
        );
    }

    public function testTrustedRefundRejectsNonRefundedDocumentWithMatchingMarker(): void
    {
        $context = new ExecutionContext();
        $creditmemo = $this->creditmemo();
        $creditmemo->setEntityId(91)
            ->setIncrementId('000000091')
            ->setState(Creditmemo::STATE_OPEN);
        $this->expectException(InvariantViolationException::class);

        $context->execute(
            self::KEY,
            function () use ($context, $creditmemo): void {
                $this->propagateMarker($context, $creditmemo);
                $context->assertTrustedRefund($creditmemo, 91, self::KEY);
            }
        );
    }

    public function testTrustedRefundRejectsBlankIncrementId(): void
    {
        $context = new ExecutionContext();
        $creditmemo = $this->creditmemo();
        $creditmemo->setEntityId(91)
            ->setIncrementId(' ')
            ->setState(Creditmemo::STATE_REFUNDED);
        $this->expectException(InvariantViolationException::class);

        $context->execute(
            self::KEY,
            function () use ($context, $creditmemo): void {
                $this->propagateMarker($context, $creditmemo);
                $context->assertTrustedRefund($creditmemo, 91, self::KEY);
            }
        );
    }

    public function testTrustedRefundRejectsDifferentTransientOperationMarker(): void
    {
        $context = new ExecutionContext();
        $creditmemo = $this->creditmemo();
        $creditmemo->setEntityId(91)
            ->setIncrementId('000000091')
            ->setState(Creditmemo::STATE_REFUNDED)
            ->setData(
                ExecutionContext::CREDITMEMO_DATA_KEY,
                'creditmemo:exchange:8:version:4'
            );
        $this->expectException(InvariantViolationException::class);

        $context->execute(
            self::KEY,
            static function () use ($context, $creditmemo): void {
                $context->assertTrustedRefund($creditmemo, 91, self::KEY);
            }
        );
    }

    private function arguments(string $operationKey): CreditmemoCreationArgumentsInterface
    {
        $extension = $this->createMock(
            CreditmemoCreationArgumentsExtensionStubInterface::class
        );
        $extension->method('getBonlinecoExchangeOperationKey')->willReturn($operationKey);
        $arguments = $this->createMock(CreditmemoCreationArgumentsInterface::class);
        $arguments->method('getExtensionAttributes')->willReturn($extension);

        return $arguments;
    }

    private function creditmemo(): Creditmemo
    {
        return $this->getMockBuilder(Creditmemo::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
    }

    private function propagateMarker(ExecutionContext $context, Creditmemo $creditmemo): void
    {
        (new CreditmemoDocumentFactoryPlugin($context))->afterCreateFromOrder(
            $this->factory(),
            $creditmemo,
            $this->createMock(OrderInterface::class),
            [],
            null,
            false,
            $this->arguments(self::KEY)
        );
    }

    private function factory(): CreditmemoDocumentFactory
    {
        return $this->getMockBuilder(CreditmemoDocumentFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
    }
}
