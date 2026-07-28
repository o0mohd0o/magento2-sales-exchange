<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Unit\Model\Creation;

use Bonlineco\SalesExchange\Api\ConfigInterface;
use Bonlineco\SalesExchange\Api\Data\CreateExchangeRequestInterface;
use Bonlineco\SalesExchange\Api\Data\ReturnSelectionInterface;
use Bonlineco\SalesExchange\Api\ExchangeEligibilityInterface;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Creation\CreateExchange;
use Bonlineco\SalesExchange\Model\Creation\CreateInputValidator;
use Bonlineco\SalesExchange\Model\ExchangeFactory;
use Bonlineco\SalesExchange\Model\ResourceModel\AllocationGuard;
use Bonlineco\SalesExchange\Model\ReturnableOrderItemValidator;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Model\OrderMutexInterface;
use PHPUnit\Framework\TestCase;

/**
 * Creation must resolve eligibility only after locking the sales order.
 */
class CreateExchangeMutexTest extends TestCase
{
    public function testEligibilityRunsInsideOrderMutex(): void
    {
        $insideMutex = false;
        $orderMutex = $this->orderMutex($insideMutex);
        $eligibility = $this->createMock(ExchangeEligibilityInterface::class);
        $eligibility->expects(self::once())
            ->method('execute')
            ->with(5)
            ->willReturnCallback(
                static function () use (&$insideMutex): OrderInterface {
                    self::assertTrue($insideMutex);
                    throw new InvariantViolationException(
                        __('Stop after proving mutex order.')
                    );
                }
            );
        $service = $this->service([
            'orderMutex' => $orderMutex,
            'exchangeEligibility' => $eligibility,
        ]);

        $this->expectException(InvariantViolationException::class);
        $service->execute($this->request(5));
    }

    public function testInvalidOrderIdIsRejectedBeforeCallingMutex(): void
    {
        $orderMutex = $this->createMock(OrderMutexInterface::class);
        $orderMutex->expects(self::never())->method('execute');
        $service = $this->service(['orderMutex' => $orderMutex]);

        $this->expectException(InvariantViolationException::class);
        $service->execute($this->request(0));
    }

    public function testAllocationRowsArePrelockedInDeterministicOrder(): void
    {
        $insideMutex = false;
        $orderMutex = $this->orderMutex($insideMutex);
        $item10 = $this->orderItem(10);
        $item20 = $this->orderItem(20);
        $order = $this->createMock(OrderInterface::class);
        $order->method('getItems')->willReturn([$item20, $item10]);
        $locks = [];
        $eligibility = $this->createMock(ExchangeEligibilityInterface::class);
        $eligibility->method('execute')
            ->willReturnCallback(
                static function () use (
                    $order,
                    &$locks,
                    &$insideMutex
                ): OrderInterface {
                    self::assertTrue($insideMutex);
                    self::assertSame(
                        [10, 20],
                        $locks,
                        'Fresh eligibility must run after allocation locks.'
                    );

                    return $order;
                }
            );
        $config = $this->createMock(ConfigInterface::class);
        $config->method('getAllowedReasonCodes')->willReturn(['unused']);
        $inputValidator = $this->createMock(CreateInputValidator::class);
        $returnableValidator = $this->createMock(
            ReturnableOrderItemValidator::class
        );
        $allocationGuard = $this->createMock(AllocationGuard::class);
        $allocationGuard->method('lock')
            ->willReturnCallback(
                static function (int $orderItemId) use (
                    &$locks,
                    &$insideMutex
                ): void {
                    self::assertTrue($insideMutex);
                    $locks[] = $orderItemId;
                }
            );
        $exchangeFactory = $this->createMock(ExchangeFactory::class);
        $exchangeFactory->method('create')
            ->willThrowException(
                new InvariantViolationException(
                    __('Stop before persistence.')
                )
            );
        $service = $this->service([
            'orderMutex' => $orderMutex,
            'exchangeEligibility' => $eligibility,
            'config' => $config,
            'inputValidator' => $inputValidator,
            'returnableOrderItemValidator' => $returnableValidator,
            'allocationGuard' => $allocationGuard,
            'exchangeFactory' => $exchangeFactory,
        ]);
        $request = $this->request(
            5,
            [$this->selection(20), $this->selection(10)]
        );

        try {
            $service->execute($request);
            self::fail('The test must stop immediately before persistence.');
        } catch (InvariantViolationException $exception) {
            self::assertSame([10, 20], $locks);
            self::assertFalse($insideMutex);
        }
    }

    /**
     * @param ReturnSelectionInterface[] $returnItems
     */
    private function request(
        int $orderId,
        array $returnItems = []
    ): CreateExchangeRequestInterface {
        $request = $this->createMock(CreateExchangeRequestInterface::class);
        $request->method('getOrderId')->willReturn($orderId);
        $request->method('getReturnItems')->willReturn($returnItems);

        return $request;
    }

    private function selection(int $orderItemId): ReturnSelectionInterface
    {
        $selection = $this->createMock(ReturnSelectionInterface::class);
        $selection->method('getOrderItemId')->willReturn($orderItemId);

        return $selection;
    }

    private function orderItem(int $orderItemId): OrderItemInterface
    {
        $item = $this->createMock(OrderItemInterface::class);
        $item->method('getItemId')->willReturn($orderItemId);

        return $item;
    }

    private function orderMutex(bool &$insideMutex): OrderMutexInterface
    {
        $orderMutex = $this->createMock(OrderMutexInterface::class);
        $orderMutex->expects(self::once())
            ->method('execute')
            ->with(
                5,
                self::callback(
                    static fn ($value): bool => is_callable($value)
                )
            )
            ->willReturnCallback(
                static function (
                    int $orderId,
                    callable $callback,
                    array $arguments = []
                ) use (&$insideMutex) {
                    self::assertSame(5, $orderId);
                    $insideMutex = true;
                    try {
                        return $callback(...$arguments);
                    } finally {
                        $insideMutex = false;
                    }
                }
            );

        return $orderMutex;
    }

    /**
     * @param array<string, object> $properties
     */
    private function service(array $properties): CreateExchange
    {
        $reflection = new \ReflectionClass(CreateExchange::class);
        /** @var CreateExchange $service */
        $service = $reflection->newInstanceWithoutConstructor();
        foreach ($properties as $name => $value) {
            $property = $reflection->getProperty($name);
            $property->setValue($service, $value);
        }

        return $service;
    }
}
