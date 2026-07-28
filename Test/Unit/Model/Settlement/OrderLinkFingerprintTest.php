<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Unit\Model\Settlement;

use Bonlineco\SalesExchange\Api\Data\DocumentLinkInterface;
use Bonlineco\SalesExchange\Api\Data\ExchangeInterface;
use Bonlineco\SalesExchange\Api\DocumentType;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Settlement\OperationKeys;
use Bonlineco\SalesExchange\Model\Settlement\ReconcileSettlement;
use Magento\Sales\Api\Data\OrderInterface;
use PHPUnit\Framework\TestCase;

class OrderLinkFingerprintTest extends TestCase
{
    public function testCanonicalOrderLinkFingerprintIsAccepted(): void
    {
        $this->assertOrderLink($this->row());

        self::assertTrue(true);
    }

    public function testTamperedOrderLinkFingerprintIsRejected(): void
    {
        $row = $this->row();
        $row[DocumentLinkInterface::SNAPSHOT_HASH] = str_repeat('b', 64);

        $this->expectException(InvariantViolationException::class);
        $this->expectExceptionMessage(
            'linked native replacement order differs from its canonical snapshot'
        );

        $this->assertOrderLink($row);
    }

    /**
     * @param array<string, int|string> $row
     */
    private function assertOrderLink(array $row): void
    {
        $exchange = $this->createMock(ExchangeInterface::class);
        $exchange->method('getEntityId')->willReturn(7);
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(55);
        $order->method('getIncrementId')->willReturn('000000055');
        $order->method('getOrderCurrencyCode')->willReturn('EGP');
        $order->method('getBaseCurrencyCode')->willReturn('EGP');

        $reflection = new \ReflectionClass(ReconcileSettlement::class);
        /** @var ReconcileSettlement $command */
        $command = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('operationKeys')
            ->setValue($command, new OperationKeys());
        $reflection->getMethod('assertOrderLink')->invoke(
            $command,
            $row,
            $exchange,
            $order,
            $this->snapshot()
        );
    }

    /**
     * @return array<string, int|string>
     */
    private function row(): array
    {
        return [
            DocumentLinkInterface::EXCHANGE_ID => 7,
            DocumentLinkInterface::DOCUMENT_TYPE => DocumentType::ORDER,
            DocumentLinkInterface::DOCUMENT_ID => 55,
            DocumentLinkInterface::INCREMENT_ID => '000000055',
            DocumentLinkInterface::OPERATION_KEY
                => 'sales-exchange:replacement-order:v1:7',
            DocumentLinkInterface::ITEM_QUANTITIES_JSON => '{"11":"1.0000"}',
            DocumentLinkInterface::SNAPSHOT_HASH => str_repeat('a', 64),
            DocumentLinkInterface::AMOUNT => '120.0000',
            DocumentLinkInterface::EXPECTED_AMOUNT => '120.0000',
            DocumentLinkInterface::BASE_AMOUNT => '120.0000',
            DocumentLinkInterface::CURRENCY_CODE => 'EGP',
            DocumentLinkInterface::BASE_CURRENCY_CODE => 'EGP',
        ];
    }

    /**
     * @return array{
     *     amount: string,
     *     base_amount: string,
     *     expected_amount: string,
     *     item_quantities_json: string,
     *     snapshot_hash: string,
     *     item_ids: array<int, int>
     * }
     */
    private function snapshot(): array
    {
        return [
            'amount' => '120.0000',
            'base_amount' => '120.0000',
            'expected_amount' => '120.0000',
            'item_quantities_json' => '{"11":"1.0000"}',
            'snapshot_hash' => str_repeat('a', 64),
            'item_ids' => [1 => 11],
        ];
    }
}
