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
use Magento\Quote\Api\Data\CartInterface;

/**
 * In-process proof that one exact quote belongs to a replacement-order intent.
 */
class ExecutionContext
{
    private const INTENT_HASH_PATTERN = '/^[a-f0-9]{64}$/D';

    /**
     * Active exchange identifier.
     *
     * @var int|null
     */
    private ?int $exchangeId = null;

    /**
     * Active canonical intent fingerprint.
     *
     * @var string|null
     */
    private ?string $intentHash = null;

    /**
     * Quote explicitly bound to the active intent.
     *
     * @var CartInterface|null
     */
    private ?CartInterface $trustedQuote = null;

    /**
     * Execute one replacement-order intent and clear all trust state afterward.
     *
     * @param int $exchangeId
     * @param string $intentHash
     * @param callable $callback
     * @return mixed
     */
    public function execute(int $exchangeId, string $intentHash, callable $callback)
    {
        $this->activate($exchangeId, $intentHash);
        try {
            return $callback();
        } finally {
            $this->_resetState();
        }
    }

    /**
     * Bind the active intent to one quote.
     *
     * A repository reload of this same persisted quote is also trusted while
     * the context remains active.
     *
     * @param CartInterface $quote
     * @return void
     */
    public function markQuote(CartInterface $quote): void
    {
        if ($this->exchangeId === null || $this->intentHash === null) {
            throw new InvariantViolationException(
                __('A replacement-order intent must be active before its quote can be marked.')
            );
        }
        if ($this->trustedQuote !== null && $this->trustedQuote !== $quote) {
            throw new InvariantViolationException(
                __('A different quote is already marked for this replacement-order intent.')
            );
        }

        $this->trustedQuote = $quote;
    }

    /**
     * Determine whether this exact intent is active.
     *
     * @param int $exchangeId
     * @param string $intentHash
     * @return bool
     */
    public function isActiveFor(int $exchangeId, string $intentHash): bool
    {
        return $exchangeId > 0
            && $this->exchangeId === $exchangeId
            && $this->intentHash !== null
            && hash_equals($this->intentHash, $intentHash);
    }

    /**
     * Determine whether a quote is bound to the active intent.
     *
     * @param CartInterface|null $quote
     * @return bool
     */
    public function isTrustedQuote(?CartInterface $quote): bool
    {
        if ($quote === null
            || $this->exchangeId === null
            || $this->intentHash === null
            || $this->trustedQuote === null
        ) {
            return false;
        }
        if ($quote === $this->trustedQuote) {
            return true;
        }

        $trustedId = $this->normalizeQuoteId($this->trustedQuote->getId());
        $candidateId = $this->normalizeQuoteId($quote->getId());

        return $trustedId !== null
            && $candidateId !== null
            && $trustedId === $candidateId
            && $this->hasActiveMarkers($this->trustedQuote)
            && $this->hasActiveMarkers($quote);
    }

    /**
     * Clear all request-local trust state.
     *
     * @return void
     */
    public function _resetState(): void
    {
        $this->exchangeId = null;
        $this->intentHash = null;
        $this->trustedQuote = null;
    }

    /**
     * Validate and activate an intent.
     *
     * @param int $exchangeId
     * @param string $intentHash
     * @return void
     */
    private function activate(int $exchangeId, string $intentHash): void
    {
        if ($exchangeId <= 0 || !preg_match(self::INTENT_HASH_PATTERN, $intentHash)) {
            throw new InvariantViolationException(
                __('The replacement-order execution intent is invalid.')
            );
        }
        if ($this->exchangeId !== null || $this->intentHash !== null) {
            throw new InvariantViolationException(
                __('A replacement-order intent is already active in this request.')
            );
        }

        $this->exchangeId = $exchangeId;
        $this->intentHash = $intentHash;
    }

    /**
     * Normalize Magento's integer or numeric-string quote identifier.
     *
     * @param mixed $value
     * @return int|null
     */
    private function normalizeQuoteId($value): ?int
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

    /**
     * Require a reloaded quote to prove that it carries the active intent.
     */
    private function hasActiveMarkers(CartInterface $quote): bool
    {
        if (!$quote instanceof AbstractModel
            || $this->exchangeId === null
            || $this->intentHash === null
        ) {
            return false;
        }
        $exchangeId = $this->normalizeQuoteId(
            $quote->getData(Marker::EXCHANGE_ID)
        );
        $intentHash = $quote->getData(Marker::INTENT_HASH);

        return $exchangeId === $this->exchangeId
            && is_string($intentHash)
            && hash_equals($this->intentHash, $intentHash);
    }
}
