<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Integration\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Sales\Model\OrderMutexInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Exercise the real nested sales transaction used by lifecycle synchronizers.
 *
 * Database isolation is disabled deliberately: the test must let the outer
 * OrderMutex own transaction level one so its forced rollback reaches MySQL.
 * Every row uses a test-only identity and is removed in a finally block.
 *
 * @magentoAppIsolation enabled
 * @magentoDbIsolation disabled
 */
class NativeLifecycleTransactionTest extends TestCase
{
    private const ORIGINAL_INCREMENT_ID = 'BSE-INTEGRATION-ORIGINAL';
    private const REPLACEMENT_INCREMENT_ID = 'BSE-INTEGRATION-REPLACEMENT';
    private const EXCHANGE_INCREMENT_ID = 'BSE-INTEGRATION-EXCHANGE';
    private const INTENT_HASH =
        'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';

    public function testNestedOrderMutexRollsBackNativeAndExchangeWrites(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $resourceConnection = $objectManager->get(
            ResourceConnection::class
        );
        $connection = $resourceConnection->getConnection('sales');
        $tables = $this->tables($resourceConnection);
        $this->cleanup($connection, $tables);

        try {
            $originalOrderId = $this->insertOrder(
                $connection,
                $tables['order'],
                self::ORIGINAL_INCREMENT_ID,
                null,
                null
            );
            $replacementOrderId = $this->insertOrder(
                $connection,
                $tables['order'],
                self::REPLACEMENT_INCREMENT_ID,
                1,
                self::INTENT_HASH
            );
            $exchangeId = $this->insertExchange(
                $connection,
                $tables['exchange'],
                $originalOrderId
            );
            $connection->update(
                $tables['order'],
                ['bonlineco_exchange_id' => $exchangeId],
                ['entity_id = ?' => $replacementOrderId]
            );

            $orderMutex = $objectManager->get(OrderMutexInterface::class);
            try {
                $orderMutex->execute(
                    $originalOrderId,
                    function () use (
                        $orderMutex,
                        $replacementOrderId,
                        $connection,
                        $tables,
                        $exchangeId
                    ): void {
                        $orderMutex->execute(
                            $replacementOrderId,
                            function () use (
                                $connection,
                                $tables,
                                $replacementOrderId,
                                $exchangeId
                            ): void {
                                $connection->update(
                                    $tables['order'],
                                    [
                                        'state' => 'canceled',
                                        'status' => 'canceled',
                                        'total_canceled' => '20.0000',
                                    ],
                                    ['entity_id = ?' => $replacementOrderId]
                                );
                                $connection->update(
                                    $tables['exchange'],
                                    [
                                        'replacement_status' => 'cancelled',
                                        'native_replacement_amount' =>
                                            '0.0000',
                                        'balance_amount' => '0.0000',
                                        'version' => 2,
                                    ],
                                    ['entity_id = ?' => $exchangeId]
                                );
                                $connection->insert(
                                    $tables['history'],
                                    [
                                        'exchange_id' => $exchangeId,
                                        'action' =>
                                            'forced_rollback_probe',
                                        'status_dimension' =>
                                            'replacement',
                                        'from_value' => 'ordered',
                                        'to_value' => 'cancelled',
                                        'actor_type' => 'system',
                                    ]
                                );

                                throw new \RuntimeException(
                                    'forced native lifecycle rollback'
                                );
                            }
                        );
                    }
                );
                self::fail('The forced lifecycle failure was not propagated.');
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'forced native lifecycle rollback',
                    $exception->getMessage()
                );
            }

            self::assertSame(0, $connection->getTransactionLevel());
            $orderRow = $connection->fetchRow(
                $connection->select()
                    ->from(
                        $tables['order'],
                        [
                            'state',
                            'status',
                            'total_canceled',
                            'bonlineco_exchange_id',
                            'bonlineco_exchange_intent_hash',
                        ]
                    )
                    ->where('entity_id = ?', $replacementOrderId)
            );
            self::assertIsArray($orderRow);
            self::assertSame('new', $orderRow['state']);
            self::assertSame('pending', $orderRow['status']);
            self::assertNull($orderRow['total_canceled']);
            self::assertSame(
                $exchangeId,
                (int)$orderRow['bonlineco_exchange_id']
            );
            self::assertSame(
                self::INTENT_HASH,
                $orderRow['bonlineco_exchange_intent_hash']
            );

            $exchangeRow = $connection->fetchRow(
                $connection->select()
                    ->from(
                        $tables['exchange'],
                        [
                            'replacement_status',
                            'native_replacement_amount',
                            'balance_amount',
                            'version',
                        ]
                    )
                    ->where('entity_id = ?', $exchangeId)
            );
            self::assertIsArray($exchangeRow);
            self::assertSame(
                'ordered',
                $exchangeRow['replacement_status']
            );
            self::assertSame(
                '20.0000',
                $exchangeRow['native_replacement_amount']
            );
            self::assertSame('20.0000', $exchangeRow['balance_amount']);
            self::assertSame(1, (int)$exchangeRow['version']);
            self::assertSame(
                '0',
                (string)$connection->fetchOne(
                    $connection->select()
                        ->from($tables['history'], ['COUNT(*)'])
                        ->where('exchange_id = ?', $exchangeId)
                )
            );
        } finally {
            $this->cleanup($connection, $tables);
        }
    }

    private function insertOrder(
        AdapterInterface $connection,
        string $table,
        string $incrementId,
        ?int $exchangeId,
        ?string $intentHash
    ): int {
        $connection->insert(
            $table,
            [
                'state' => 'new',
                'status' => 'pending',
                'increment_id' => $incrementId,
                'customer_is_guest' => 1,
                'customer_email' => 'sales-exchange-integration@example.com',
                'order_currency_code' => 'USD',
                'base_currency_code' => 'USD',
                'global_currency_code' => 'USD',
                'store_currency_code' => 'USD',
                'grand_total' => '20.0000',
                'base_grand_total' => '20.0000',
                'subtotal' => '20.0000',
                'base_subtotal' => '20.0000',
                'total_item_count' => 0,
                'bonlineco_exchange_id' => $exchangeId,
                'bonlineco_exchange_intent_hash' => $intentHash,
            ]
        );

        return (int)$connection->lastInsertId($table);
    }

    private function insertExchange(
        AdapterInterface $connection,
        string $table,
        int $originalOrderId
    ): int {
        $connection->insert(
            $table,
            [
                'increment_id' => self::EXCHANGE_INCREMENT_ID,
                'original_order_id' => $originalOrderId,
                'currency_code' => 'USD',
                'base_currency_code' => 'USD',
                'exchange_status' => 'in_progress',
                'return_status' => 'accepted',
                'replacement_status' => 'ordered',
                'settlement_status' => 'pending',
                'native_replacement_amount' => '20.0000',
                'base_native_replacement_amount' => '20.0000',
                'replacement_amount' => '20.0000',
                'balance_amount' => '20.0000',
                'version' => 1,
            ]
        );

        return (int)$connection->lastInsertId($table);
    }

    /**
     * @return array{order: string, exchange: string, history: string}
     */
    private function tables(ResourceConnection $resourceConnection): array
    {
        return [
            'order' => $resourceConnection->getTableName('sales_order'),
            'exchange' => $resourceConnection->getTableName(
                'bonlineco_sales_exchange'
            ),
            'history' => $resourceConnection->getTableName(
                'bonlineco_sales_exchange_history'
            ),
        ];
    }

    /**
     * @param array{order: string, exchange: string, history: string} $tables
     */
    private function cleanup(
        AdapterInterface $connection,
        array $tables
    ): void {
        $exchangeIds = $connection->fetchCol(
            $connection->select()
                ->from($tables['exchange'], ['entity_id'])
                ->where('increment_id = ?', self::EXCHANGE_INCREMENT_ID)
        );
        if ($exchangeIds !== []) {
            $connection->delete(
                $tables['history'],
                ['exchange_id IN (?)' => $exchangeIds]
            );
            $connection->delete(
                $tables['exchange'],
                ['entity_id IN (?)' => $exchangeIds]
            );
        }
        $connection->delete(
            $tables['order'],
            [
                'increment_id IN (?)' => [
                    self::ORIGINAL_INCREMENT_ID,
                    self::REPLACEMENT_INCREMENT_ID,
                ],
            ]
        );
    }
}
