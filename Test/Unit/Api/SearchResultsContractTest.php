<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Unit\Api;

use Bonlineco\SalesExchange\Api\Data\DocumentLinkSearchResultsInterface;
use Bonlineco\SalesExchange\Api\Data\ExchangeSearchResultsInterface;
use Bonlineco\SalesExchange\Api\Data\HistorySearchResultsInterface;
use Bonlineco\SalesExchange\Api\Data\ReplacementItemSearchResultsInterface;
use Bonlineco\SalesExchange\Api\Data\ReturnItemSearchResultsInterface;
use Bonlineco\SalesExchange\Api\Data\SettlementSearchResultsInterface;
use Bonlineco\SalesExchange\Model\DocumentLinkSearchResults;
use Bonlineco\SalesExchange\Model\ExchangeSearchResults;
use Bonlineco\SalesExchange\Model\HistorySearchResults;
use Bonlineco\SalesExchange\Model\ReplacementItemSearchResults;
use Bonlineco\SalesExchange\Model\ReturnItemSearchResults;
use Bonlineco\SalesExchange\Model\SettlementSearchResults;
use Magento\Framework\Api\SearchResults;
use Magento\Framework\Api\SearchResultsInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Protect inherited Magento search-result signatures from DI compile fatals.
 */
class SearchResultsContractTest extends TestCase
{
    /**
     * @dataProvider searchResultsProvider
     */
    #[DataProvider('searchResultsProvider')]
    public function testImplementationLoadsWithNativeMethodSignatures(
        string $implementation,
        string $interface
    ): void {
        self::assertTrue(interface_exists($interface));
        self::assertTrue(class_exists($implementation));
        self::assertTrue(is_subclass_of($implementation, SearchResults::class));
        self::assertTrue(is_a($implementation, $interface, true));

        foreach (['getItems', 'setItems'] as $method) {
            $nativeType = (new \ReflectionMethod(
                SearchResultsInterface::class,
                $method
            ))->getReturnType();
            $moduleType = (new \ReflectionMethod(
                $interface,
                $method
            ))->getReturnType();

            self::assertSame(
                $nativeType === null ? null : (string)$nativeType,
                $moduleType === null ? null : (string)$moduleType
            );
        }
    }

    /**
     * @return array<string, array{class-string, class-string}>
     */
    public static function searchResultsProvider(): array
    {
        return [
            'document link' => [
                DocumentLinkSearchResults::class,
                DocumentLinkSearchResultsInterface::class,
            ],
            'exchange' => [
                ExchangeSearchResults::class,
                ExchangeSearchResultsInterface::class,
            ],
            'history' => [
                HistorySearchResults::class,
                HistorySearchResultsInterface::class,
            ],
            'replacement item' => [
                ReplacementItemSearchResults::class,
                ReplacementItemSearchResultsInterface::class,
            ],
            'return item' => [
                ReturnItemSearchResults::class,
                ReturnItemSearchResultsInterface::class,
            ],
            'settlement' => [
                SettlementSearchResults::class,
                SettlementSearchResultsInterface::class,
            ],
        ];
    }
}
