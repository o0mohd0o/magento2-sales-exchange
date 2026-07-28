<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Unit\Model;

use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\ReplacementItem;
use Bonlineco\SalesExchange\Model\ReplacementItemRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guard the command-owned native order-item fulfillment link.
 */
class ReplacementItemRepositoryFulfillmentGuardTest extends TestCase
{
    public function testNewReplacementItemCannotProvideNativeOrderItemLink(): void
    {
        $item = $this->createMock(ReplacementItem::class);
        $item->method('getEntityId')->willReturn(null);
        $item->method('getReplacementOrderItemId')->willReturn(71);
        $this->expectException(InvariantViolationException::class);

        $this->invokeIdentityGuard($item);
    }

    public function testNewReplacementItemMayKeepNativeOrderItemLinkEmpty(): void
    {
        $item = $this->createMock(ReplacementItem::class);
        $item->method('getEntityId')->willReturn(null);
        $item->method('getReplacementOrderItemId')->willReturn(null);

        $this->invokeIdentityGuard($item);

        self::assertTrue(true);
    }

    /**
     * @dataProvider changedLinkProvider
     */
    #[DataProvider('changedLinkProvider')]
    public function testGenericSaveCannotChangeNativeOrderItemLink(
        ?int $persistedLink,
        ?int $incomingLink
    ): void {
        $this->expectException(InvariantViolationException::class);

        $this->invokeLinkGuard($persistedLink, $incomingLink);
    }

    /**
     * @return array<string, array{int|null, int|null}>
     */
    public static function changedLinkProvider(): array
    {
        return [
            'unfulfilled to fulfilled' => [null, 71],
            'fulfilled to unfulfilled' => [71, null],
            'fulfilled to another item' => [71, 72],
        ];
    }

    /**
     * @dataProvider unchangedLinkProvider
     */
    #[DataProvider('unchangedLinkProvider')]
    public function testGenericSaveMayPreserveNativeOrderItemLink(
        ?int $persistedLink,
        ?int $incomingLink
    ): void {
        $this->invokeLinkGuard($persistedLink, $incomingLink);

        self::assertTrue(true);
    }

    /**
     * @return array<string, array{int|null, int|null}>
     */
    public static function unchangedLinkProvider(): array
    {
        return [
            'both unfulfilled' => [null, null],
            'same fulfilled item' => [71, 71],
        ];
    }

    private function invokeIdentityGuard(ReplacementItem $item): void
    {
        $reflection = new \ReflectionClass(ReplacementItemRepository::class);
        $repository = $reflection->newInstanceWithoutConstructor();
        $reflection->getMethod('assertIdentityWasNotChanged')->invoke($repository, $item);
    }

    private function invokeLinkGuard(?int $persistedLink, ?int $incomingLink): void
    {
        $reflection = new \ReflectionClass(ReplacementItemRepository::class);
        $repository = $reflection->newInstanceWithoutConstructor();
        $reflection->getMethod('assertReplacementOrderItemLinkWasNotChanged')->invoke(
            $repository,
            $persistedLink,
            $incomingLink
        );
    }
}
