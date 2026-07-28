<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Unit\Model\Authorization;

use Bonlineco\SalesExchange\Model\Authorization\ExchangeReadAuthorization;
use Bonlineco\SalesExchange\Model\Authorization\ReplacementOrderAuthorization;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\AuthorizationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verify replacement-order creation requires every permission boundary.
 */
class ReplacementOrderAuthorizationTest extends TestCase
{
    /**
     * @dataProvider permissionCombinationProvider
     */
    #[DataProvider('permissionCombinationProvider')]
    public function testRequiresReadCustomAndNativeCreatePermissions(
        bool $readAllowed,
        bool $replacementAllowed,
        bool $nativeCreateAllowed,
        bool $expected
    ): void {
        $readAuthorization = $this->createMock(ExchangeReadAuthorization::class);
        $readAuthorization->method('isAllowed')->willReturn($readAllowed);
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(
            static function (string $resource) use (
                $replacementAllowed,
                $nativeCreateAllowed
            ): bool {
                if ($resource === ReplacementOrderAuthorization::ACL_REPLACEMENT_ORDER) {
                    return $replacementAllowed;
                }
                if ($resource === ReplacementOrderAuthorization::ACL_NATIVE_SALES_CREATE) {
                    return $nativeCreateAllowed;
                }

                self::fail('Unexpected ACL resource: ' . $resource);
            }
        );

        self::assertSame(
            $expected,
            (new ReplacementOrderAuthorization($readAuthorization, $authorization))->isAllowed()
        );
    }

    public function testAssertAllowedRejectsMissingPermission(): void
    {
        $readAuthorization = $this->createMock(ExchangeReadAuthorization::class);
        $readAuthorization->method('isAllowed')->willReturn(false);
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->expects(self::never())->method('isAllowed');
        $subject = new ReplacementOrderAuthorization($readAuthorization, $authorization);

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
            'missing replacement action' => [true, false, true, false],
            'missing native sales create' => [true, true, false, false],
        ];
    }
}
