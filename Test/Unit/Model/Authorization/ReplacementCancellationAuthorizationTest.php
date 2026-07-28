<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Unit\Model\Authorization;

use Bonlineco\SalesExchange\Model\Authorization\ExchangeReadAuthorization;
use Bonlineco\SalesExchange\Model\Authorization\ReplacementCancellationAuthorization;
use Bonlineco\SalesExchange\Model\Workflow\AdminActionMap;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\AuthorizationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verify replacement cancellation has a least-privilege ACL boundary.
 */
class ReplacementCancellationAuthorizationTest extends TestCase
{
    /**
     * @dataProvider permissionProvider
     */
    #[DataProvider('permissionProvider')]
    public function testRequiresReadGeneralCancelAndDedicatedCancel(
        bool $readAllowed,
        bool $generalCancelAllowed,
        bool $replacementCancelAllowed,
        bool $expected
    ): void {
        $read = $this->createMock(ExchangeReadAuthorization::class);
        $read->method('isAllowed')->willReturn($readAllowed);
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(
            static function (string $resource) use (
                $generalCancelAllowed,
                $replacementCancelAllowed
            ): bool {
                if ($resource === AdminActionMap::ACL_CANCEL) {
                    return $generalCancelAllowed;
                }
                if ($resource === ReplacementCancellationAuthorization::ACL_REPLACEMENT_CANCEL) {
                    return $replacementCancelAllowed;
                }
                self::fail('Unexpected ACL resource: ' . $resource);
            }
        );

        self::assertSame(
            $expected,
            (new ReplacementCancellationAuthorization(
                $read,
                $authorization
            ))->isAllowed()
        );
    }

    public function testAssertAllowedRejectsMissingReadPermission(): void
    {
        $read = $this->createMock(ExchangeReadAuthorization::class);
        $read->method('isAllowed')->willReturn(false);
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->expects(self::never())->method('isAllowed');

        $this->expectException(AuthorizationException::class);
        (new ReplacementCancellationAuthorization(
            $read,
            $authorization
        ))->assertAllowed();
    }

    /**
     * @return array<string, array{bool, bool, bool, bool}>
     */
    public static function permissionProvider(): array
    {
        return [
            'all allowed' => [true, true, true, true],
            'read denied' => [false, true, true, false],
            'general cancel denied' => [true, false, true, false],
            'replacement cancel denied' => [true, true, false, false],
        ];
    }
}
