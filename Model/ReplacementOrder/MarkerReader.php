<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\ReplacementOrder;

use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Magento\Framework\Model\AbstractModel;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Read the all-or-nothing durable identity of a replacement order.
 */
class MarkerReader
{
    /**
     * @return array{exchange_id: int, intent_hash: string}|null
     */
    public function execute(OrderInterface $order): ?array
    {
        if (!$order instanceof AbstractModel) {
            throw new InvariantViolationException(
                __('The native order implementation is not supported.')
            );
        }

        $rawExchangeId = $order->getData(Marker::EXCHANGE_ID);
        $rawIntentHash = $order->getData(Marker::INTENT_HASH);
        $hasExchangeId = $rawExchangeId !== null && $rawExchangeId !== '';
        $hasIntentHash = $rawIntentHash !== null && $rawIntentHash !== '';
        if (!$hasExchangeId && !$hasIntentHash) {
            return null;
        }

        $exchangeId = is_int($rawExchangeId)
            ? $rawExchangeId
            : (is_string($rawExchangeId)
                && preg_match('/^[1-9][0-9]*$/D', $rawExchangeId)
                    ? (int)$rawExchangeId
                    : 0);
        if (!$hasExchangeId
            || !$hasIntentHash
            || $exchangeId <= 0
            || !is_string($rawIntentHash)
            || !preg_match('/^[a-f0-9]{64}$/D', $rawIntentHash)
        ) {
            throw new InvariantViolationException(
                __('The native replacement order markers are incomplete or invalid.')
            );
        }

        return [
            'exchange_id' => $exchangeId,
            'intent_hash' => $rawIntentHash,
        ];
    }
}
