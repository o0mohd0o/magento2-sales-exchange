<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Unit\Model\Authorization;

use Bonlineco\SalesExchange\Model\Authorization\ExchangeReadAuthorization;
use Bonlineco\SalesExchange\Model\Authorization\SettlementAuthorization;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\AuthorizationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verify settlement reconciliation requires every permission boundary.
 */
class SettlementAuthorizationTest extends TestCase
{
    /**
     * @dataProvider permissionCombinationProvider
     */
    #[DataProvider('permissionCombinationProvider')]
    public function testRequiresReadSettlementAndNativeInvoicePermissions(
        bool $readAllowed,
        bool $settlementAllowed,
        bool $nativeInvoiceAllowed,
        bool $expected
    ): void {
        $readAuthorization = $this->createMock(ExchangeReadAuthorization::class);
        $readAuthorization->method('isAllowed')->willReturn($readAllowed);
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(
            static function (string $resource) use (
                $settlementAllowed,
                $nativeInvoiceAllowed
            ): bool {
                if ($resource === SettlementAuthorization::ACL_SETTLEMENT) {
                    return $settlementAllowed;
                }
                if ($resource === SettlementAuthorization::ACL_NATIVE_INVOICE) {
                    return $nativeInvoiceAllowed;
                }

                self::fail('Unexpected ACL resource: ' . $resource);
            }
        );

        self::assertSame(
            $expected,
            (new SettlementAuthorization(
                $readAuthorization,
                $authorization
            ))->isAllowed()
        );
    }

    public function testAssertAllowedRejectsMissingPermission(): void
    {
        $readAuthorization = $this->createMock(ExchangeReadAuthorization::class);
        $readAuthorization->method('isAllowed')->willReturn(false);
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->expects(self::never())->method('isAllowed');
        $subject = new SettlementAuthorization(
            $readAuthorization,
            $authorization
        );

        $this->expectException(AuthorizationException::class);
        $subject->assertAllowed();
    }

    /**
     * @return array<string, array{bool, bool, bool, bool}>
     */
    public static function permissionCombinationProvider(): array
    {
        return [
            'all permissions' => [true, true, true, true],
            'missing exchange read' => [false, true, true, false],
            'missing settlement action' => [true, false, true, false],
            'missing native invoice' => [true, true, false, false],
        ];
    }
}
