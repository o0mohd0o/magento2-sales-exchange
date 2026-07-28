<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Unit\Model\Authorization;

use Bonlineco\SalesExchange\Model\Authorization\ExchangeReadAuthorization;
use Bonlineco\SalesExchange\Model\Workflow\AdminActionMap;
use Magento\Framework\AuthorizationInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verify the exchange-read permission is the intersection of both ACLs.
 */
class ExchangeReadAuthorizationTest extends TestCase
{
    /**
     * @dataProvider permissionCombinationProvider
     */
    #[DataProvider('permissionCombinationProvider')]
    public function testRequiresBothPermissions(
        bool $exchangeViewAllowed,
        bool $salesOrderViewAllowed,
        bool $expected
    ): void {
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->expects(self::exactly(2))
            ->method('isAllowed')
            ->willReturnCallback(
                static function (string $resource) use (
                    $exchangeViewAllowed,
                    $salesOrderViewAllowed
                ): bool {
                    if ($resource === AdminActionMap::ACL_VIEW) {
                        return $exchangeViewAllowed;
                    }
                    if ($resource === AdminActionMap::ACL_SALES_ORDER_VIEW) {
                        return $salesOrderViewAllowed;
                    }

                    self::fail('Unexpected ACL resource: ' . $resource);
                }
            );

        self::assertSame(
            $expected,
            (new ExchangeReadAuthorization($authorization))->isAllowed()
        );
    }

    /**
     * @return array<string, array{bool, bool, bool}>
     */
    public static function permissionCombinationProvider(): array
    {
        return [
            'neither permission' => [false, false, false],
            'exchange permission only' => [true, false, false],
            'sales permission only' => [false, true, false],
            'both permissions' => [true, true, true],
        ];
    }
}
