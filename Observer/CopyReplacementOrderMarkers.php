<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Observer;

use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\ReplacementOrder\ExecutionContext;
use Bonlineco\SalesExchange\Model\ReplacementOrder\Marker;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Model\AbstractModel;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Bridge trusted raw markers onto the final order object before native save.
 *
 * Magento's quote fieldset conversion first writes an intermediate order and
 * then filters it through OrderInterface, which intentionally drops extension
 * columns that are not part of that service contract.
 */
class CopyReplacementOrderMarkers implements ObserverInterface
{
    private ExecutionContext $executionContext;

    public function __construct(ExecutionContext $executionContext)
    {
        $this->executionContext = $executionContext;
    }

    public function execute(Observer $observer): void
    {
        $quote = $observer->getData('quote');
        $order = $observer->getData('order');
        if (!$quote instanceof Quote
            || !$order instanceof OrderInterface
            || !$order instanceof AbstractModel
        ) {
            return;
        }

        $exchangeId = $this->normalizeId(
            $quote->getData(Marker::EXCHANGE_ID)
        );
        $intentHash = $quote->getData(Marker::INTENT_HASH);
        $hasQuoteMarker = $exchangeId !== null || $intentHash !== null;
        $hasOrderMarker = $order->getData(Marker::EXCHANGE_ID) !== null
            || $order->getData(Marker::INTENT_HASH) !== null;

        if (!$this->executionContext->isTrustedQuote($quote)) {
            if ($hasQuoteMarker || $hasOrderMarker) {
                throw new InvariantViolationException(
                    __('Replacement-order markers were submitted outside their trusted execution context.')
                );
            }

            return;
        }
        if ($exchangeId === null
            || !is_string($intentHash)
            || !preg_match('/^[a-f0-9]{64}$/D', $intentHash)
            || !$this->executionContext->isActiveFor($exchangeId, $intentHash)
        ) {
            throw new InvariantViolationException(
                __('The trusted replacement quote markers are invalid.')
            );
        }
        $this->executionContext->validateBeforeSubmit($quote);
        $orderExchangeId = $this->normalizeId(
            $order->getData(Marker::EXCHANGE_ID)
        );
        $orderIntentHash = $order->getData(Marker::INTENT_HASH);
        if (($order->getData(Marker::EXCHANGE_ID) !== null
                && $orderExchangeId !== $exchangeId)
            || ($orderIntentHash !== null
                && (!is_string($orderIntentHash)
                    || !hash_equals($intentHash, $orderIntentHash)))
        ) {
            throw new InvariantViolationException(
                __('The native order already contains conflicting replacement markers.')
            );
        }
        $this->assertExactItemMarkers($quote, $order);

        $order->setData(Marker::EXCHANGE_ID, $exchangeId);
        $order->setData(Marker::INTENT_HASH, $intentHash);
        $this->executionContext->validateBeforePlace($order);
    }

    /**
     * Ensure native conversion preserved every line marker exactly once.
     */
    private function assertExactItemMarkers(
        Quote $quote,
        OrderInterface $order
    ): void {
        $quoteMarkers = $this->extractItemMarkers(
            $quote->getAllVisibleItems()
        );
        $orderItems = $order->getItems();
        if (!is_array($orderItems)) {
            throw new InvariantViolationException(
                __('The native replacement order item set is invalid.')
            );
        }
        $orderMarkers = $this->extractItemMarkers($orderItems);
        if ($quoteMarkers === [] || $quoteMarkers !== $orderMarkers) {
            throw new InvariantViolationException(
                __('Magento converted a different replacement item marker set.')
            );
        }
    }

    /**
     * @param array<int, mixed> $items
     * @return array<int, true>
     */
    private function extractItemMarkers(array $items): array
    {
        $markers = [];
        foreach ($items as $item) {
            if (!is_object($item) || !is_callable([$item, 'getData'])) {
                throw new InvariantViolationException(
                    __('A native replacement item implementation is invalid.')
                );
            }
            $replacementItemId = $this->normalizeId(
                $item->getData(Marker::REPLACEMENT_ITEM_ID)
            );
            if ($replacementItemId === null
                || isset($markers[$replacementItemId])
            ) {
                throw new InvariantViolationException(
                    __('A native replacement item marker is missing or duplicated.')
                );
            }
            $markers[$replacementItemId] = true;
        }
        ksort($markers, SORT_NUMERIC);

        return $markers;
    }

    /**
     * @param mixed $value
     */
    private function normalizeId($value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!is_string($value) || !preg_match('/^[1-9][0-9]*$/D', $value)) {
            return null;
        }
        $normalized = (int)$value;

        return $normalized > 0 && (string)$normalized === $value
            ? $normalized
            : null;
    }
}
