<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Unit\Model\ReplacementOrder;

use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\ReplacementOrder\NativeOrderAddressValidator;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Api\Data\OrderAddressSearchResultInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderAddressRepositoryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NativeOrderAddressValidatorTest extends TestCase
{
    public function testSnapshotUsesStableRegionIdentityAndNativeAddressShape(): void
    {
        $validator = $this->validator(
            [
                $this->address(
                    'billing',
                    'القاهرة',
                    2437,
                    'historical@example.com'
                ),
                $this->address('shipping', 'القاهرة', 2437),
            ],
            [
                $this->address('billing', 'Cairo', 2437),
                $this->address('shipping', 'Cairo', 2437),
            ]
        );

        $snapshot = $validator->snapshot(
            $this->order(10, 7),
            $this->order(20, 7)
        );

        self::assertSame(2437, $snapshot['billing']['region_id']);
        self::assertSame('Cairo', $snapshot['billing']['region']);
        self::assertSame('Cairo', $snapshot['billing']['region_code']);
        self::assertSame('order@example.com', $snapshot['billing']['email']);
        self::assertNull($snapshot['billing']['customer_id']);
        self::assertNull($snapshot['billing']['customer_address_id']);
    }

    /**
     * @dataProvider regionDriftProvider
     */
    #[DataProvider('regionDriftProvider')]
    public function testSnapshotRejectsDifferentRegionIdentity(
        string $sourceRegion,
        ?int $sourceRegionId,
        string $targetRegion,
        ?int $targetRegionId
    ): void {
        $validator = $this->validator(
            [
                $this->address(
                    'billing',
                    $sourceRegion,
                    $sourceRegionId
                ),
                $this->address('shipping', 'Cairo', 2437),
            ],
            [
                $this->address(
                    'billing',
                    $targetRegion,
                    $targetRegionId
                ),
                $this->address('shipping', 'Cairo', 2437),
            ]
        );
        $this->expectException(InvariantViolationException::class);

        $validator->snapshot(
            $this->order(10, 7),
            $this->order(20, 7)
        );
    }

    /**
     * @return array<string, array{string, ?int, string, ?int}>
     */
    public static function regionDriftProvider(): array
    {
        return [
            'different region ids' => ['Cairo', 2437, 'Giza', 2438],
            'different free text' => ['Cairo', null, 'Giza', null],
            'only target has an id' => ['Cairo', null, 'Cairo', 2437],
        ];
    }

    /**
     * @param OrderAddressInterface[] $sourceAddresses
     * @param OrderAddressInterface[] $targetAddresses
     */
    private function validator(
        array $sourceAddresses,
        array $targetAddresses
    ): NativeOrderAddressValidator {
        $sourceResult = $this->createMock(
            OrderAddressSearchResultInterface::class
        );
        $sourceResult->method('getItems')->willReturn($sourceAddresses);
        $targetResult = $this->createMock(
            OrderAddressSearchResultInterface::class
        );
        $targetResult->method('getItems')->willReturn($targetAddresses);
        $repository = $this->createMock(
            OrderAddressRepositoryInterface::class
        );
        $repository->expects(self::exactly(2))
            ->method('getList')
            ->willReturnOnConsecutiveCalls($sourceResult, $targetResult);
        $criteria = $this->createMock(SearchCriteriaInterface::class);
        $builder = $this->createMock(SearchCriteriaBuilder::class);
        $builder->method('addFilter')->willReturnSelf();
        $builder->method('setPageSize')->willReturnSelf();
        $builder->method('create')->willReturn($criteria);
        $builderFactory = $this->createMock(
            SearchCriteriaBuilderFactory::class
        );
        $builderFactory->method('create')->willReturn($builder);

        return new NativeOrderAddressValidator(
            $repository,
            $builderFactory
        );
    }

    private function order(int $entityId, ?int $customerId): OrderInterface
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn($entityId);
        $order->method('getCustomerId')->willReturn($customerId);
        $order->method('getCustomerEmail')->willReturn('order@example.com');

        return $order;
    }

    private function address(
        string $type,
        string $region,
        ?int $regionId,
        string $email = 'order@example.com'
    ): OrderAddressInterface {
        $address = $this->createMock(OrderAddressInterface::class);
        $address->method('getAddressType')->willReturn($type);
        $address->method('getPrefix')->willReturn(null);
        $address->method('getFirstname')->willReturn('Test');
        $address->method('getMiddlename')->willReturn(null);
        $address->method('getLastname')->willReturn('Customer');
        $address->method('getSuffix')->willReturn(null);
        $address->method('getCompany')->willReturn(null);
        $address->method('getStreet')->willReturn(['1 Main Street']);
        $address->method('getCity')->willReturn('Cairo');
        $address->method('getRegion')->willReturn($region);
        $address->method('getRegionId')->willReturn($regionId);
        $address->method('getRegionCode')->willReturn('Cairo');
        $address->method('getPostcode')->willReturn('11511');
        $address->method('getCountryId')->willReturn('EG');
        $address->method('getTelephone')->willReturn('01000000000');
        $address->method('getFax')->willReturn(null);
        $address->method('getVatId')->willReturn(null);
        $address->method('getEmail')->willReturn($email);
        $address->method('getCustomerId')->willReturn(null);
        $address->method('getCustomerAddressId')->willReturn(null);

        return $address;
    }
}
