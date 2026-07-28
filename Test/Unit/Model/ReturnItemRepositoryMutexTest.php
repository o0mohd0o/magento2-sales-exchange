<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Unit\Model;

use Bonlineco\SalesExchange\Api\Data\ExchangeInterface;
use Bonlineco\SalesExchange\Api\Data\ReturnItemInterface;
use Bonlineco\SalesExchange\Api\Status\ExchangeStatus;
use Bonlineco\SalesExchange\Api\Status\ReturnStatus;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Exchange;
use Bonlineco\SalesExchange\Model\ExchangeFactory;
use Bonlineco\SalesExchange\Model\Order\FreshOrderLoader;
use Bonlineco\SalesExchange\Model\ResourceModel\AllocationGuard;
use Bonlineco\SalesExchange\Model\ResourceModel\Exchange as ExchangeResource;
use Bonlineco\SalesExchange\Model\ResourceModel\ReturnItem as ReturnItemResource;
use Bonlineco\SalesExchange\Model\ReturnItem;
use Bonlineco\SalesExchange\Model\ReturnItemRepository;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Sales\Model\OrderMutexInterface;
use PHPUnit\Framework\TestCase;

/**
 * All supported allocation increases share the sales-order mutex.
 */
class ReturnItemRepositoryMutexTest extends TestCase
{
    public function testOrderMutexWrapsAllocationLockAndFreshOrderRead(): void
    {
        $insideMutex = false;
        $allocationLocked = false;
        $exchange = $this->exchange();
        $exchangeFactory = $this->createMock(ExchangeFactory::class);
        $exchangeFactory->method('create')->willReturn($exchange);
        $exchangeResource = $this->createMock(ExchangeResource::class);
        $exchangeResource->method('load')->willReturn($exchangeResource);
        $exchangeResource->method('getDataForUpdate')->willReturn([
            ExchangeInterface::ORIGINAL_ORDER_ID => 5,
            ExchangeInterface::EXCHANGE_STATUS => ExchangeStatus::DRAFT,
            ExchangeInterface::RETURN_STATUS => ReturnStatus::PENDING,
            ExchangeInterface::VERSION => 1,
        ]);
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('rollBack');
        $returnItemResource = $this->createMock(ReturnItemResource::class);
        $returnItemResource->method('getConnection')->willReturn($connection);
        $allocationGuard = $this->createMock(AllocationGuard::class);
        $allocationGuard->expects(self::once())
            ->method('lock')
            ->with(10)
            ->willReturnCallback(
                static function () use (
                    &$insideMutex,
                    &$allocationLocked
                ): void {
                    self::assertTrue($insideMutex);
                    $allocationLocked = true;
                }
            );
        $freshOrderLoader = $this->createMock(FreshOrderLoader::class);
        $freshOrderLoader->expects(self::once())
            ->method('execute')
            ->with(5)
            ->willReturnCallback(
                static function () use (
                    &$insideMutex,
                    &$allocationLocked
                ) {
                    self::assertTrue($insideMutex);
                    self::assertTrue($allocationLocked);
                    throw new InvariantViolationException(
                        __('Stop after proving lock order.')
                    );
                }
            );
        $repository = $this->repository([
            'exchangeFactory' => $exchangeFactory,
            'exchangeResource' => $exchangeResource,
            'returnItemResource' => $returnItemResource,
            'allocationGuard' => $allocationGuard,
            'freshOrderLoader' => $freshOrderLoader,
            'orderMutex' => $this->orderMutex($insideMutex),
        ]);

        try {
            $repository->save($this->returnItem());
            self::fail('The test must stop after the fresh order read.');
        } catch (InvariantViolationException $exception) {
            self::assertTrue($allocationLocked);
            self::assertFalse($insideMutex);
        }
    }

    private function exchange(): Exchange
    {
        /** @var Exchange $exchange */
        $exchange = (new \ReflectionClass(Exchange::class))
            ->newInstanceWithoutConstructor();
        $exchange->setEntityId(1);
        $exchange->setOriginalOrderId(5);

        return $exchange;
    }

    private function returnItem(): ReturnItem
    {
        /** @var ReturnItem $returnItem */
        $returnItem = (new \ReflectionClass(ReturnItem::class))
            ->newInstanceWithoutConstructor();
        $returnItem->setExchangeId(1);
        $returnItem->setOrderItemId(10);

        return $returnItem;
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
    private function repository(array $properties): ReturnItemRepository
    {
        $reflection = new \ReflectionClass(ReturnItemRepository::class);
        /** @var ReturnItemRepository $repository */
        $repository = $reflection->newInstanceWithoutConstructor();
        foreach ($properties as $name => $value) {
            $property = $reflection->getProperty($name);
            $property->setValue($repository, $value);
        }

        return $repository;
    }
}
