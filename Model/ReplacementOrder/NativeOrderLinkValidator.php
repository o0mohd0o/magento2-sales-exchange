<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\ReplacementOrder;

use Bonlineco\SalesExchange\Api\Data\DocumentLinkInterface;
use Bonlineco\SalesExchange\Api\Data\ExchangeInterface;
use Bonlineco\SalesExchange\Api\DocumentType;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Settlement\OperationKeys;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Validate the one immutable commercial fingerprint for a replacement order.
 */
class NativeOrderLinkValidator
{
    private OperationKeys $operationKeys;

    public function __construct(OperationKeys $operationKeys)
    {
        $this->operationKeys = $operationKeys;
    }

    /**
     * @param array<int, array<string, mixed>> $documentRows
     * @param array{
     *     amount: string,
     *     base_amount: string,
     *     expected_amount: string,
     *     item_quantities_json: string,
     *     snapshot_hash: string,
     *     item_ids: array<int, int>
     * } $snapshot
     */
    public function execute(
        ExchangeInterface $exchange,
        OrderInterface $order,
        array $documentRows,
        array $snapshot
    ): void {
        $orderRows = [];
        foreach ($documentRows as $row) {
            if ((string)($row[
                DocumentLinkInterface::DOCUMENT_TYPE
            ] ?? '') === DocumentType::ORDER) {
                $orderRows[] = $row;
            }
        }
        if (count($orderRows) !== 1) {
            throw new InvariantViolationException(
                __('The exchange requires exactly one replacement order link.')
            );
        }

        $row = $orderRows[0];
        $matches = (int)($row[DocumentLinkInterface::EXCHANGE_ID] ?? 0)
                === (int)$exchange->getEntityId()
            && (int)($row[DocumentLinkInterface::DOCUMENT_ID] ?? 0)
                === (int)$order->getEntityId()
            && (string)($row[DocumentLinkInterface::INCREMENT_ID] ?? '')
                === (string)$order->getIncrementId()
            && (string)($row[DocumentLinkInterface::OPERATION_KEY] ?? '')
                === $this->operationKeys->replacementOrder(
                    (int)$exchange->getEntityId()
                )
            && (string)($row[
                DocumentLinkInterface::ITEM_QUANTITIES_JSON
            ] ?? '') === $snapshot['item_quantities_json']
            && is_string($row[DocumentLinkInterface::SNAPSHOT_HASH] ?? null)
            && hash_equals(
                $snapshot['snapshot_hash'],
                (string)$row[DocumentLinkInterface::SNAPSHOT_HASH]
            )
            && (string)($row[DocumentLinkInterface::AMOUNT] ?? '')
                === $snapshot['amount']
            && (string)($row[DocumentLinkInterface::BASE_AMOUNT] ?? '')
                === $snapshot['base_amount']
            && (string)($row[DocumentLinkInterface::EXPECTED_AMOUNT] ?? '')
                === $snapshot['expected_amount']
            && (string)($row[DocumentLinkInterface::CURRENCY_CODE] ?? '')
                === (string)$order->getOrderCurrencyCode()
            && (string)($row[
                DocumentLinkInterface::BASE_CURRENCY_CODE
            ] ?? '') === (string)$order->getBaseCurrencyCode();
        if (!$matches) {
            throw new InvariantViolationException(
                __(
                    'The native order differs from its immutable replacement '
                    . 'order link.'
                )
            );
        }
    }
}
