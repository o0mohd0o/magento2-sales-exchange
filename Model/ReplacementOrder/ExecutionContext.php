<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\ReplacementOrder;

use Bonlineco\SalesExchange\Api\Data\ReplacementItemInterface;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Magento\Framework\Model\AbstractModel;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\OrderInterface;

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
     * Server-frozen replacement rows keyed by replacement item identifier.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $frozenRows = [];

    private ?\Closure $preSubmitValidator = null;

    private ?\Closure $prePlaceOrderValidator = null;

    private ?\Closure $postSaveOrderValidator = null;

    /**
     * Exact reloaded quote accepted at Magento's submit-before boundary.
     *
     * @var Quote|null
     */
    private ?Quote $submittedQuote = null;

    /**
     * Exact converted order emitted from the accepted submitted quote.
     *
     * @var OrderInterface|null
     */
    private ?OrderInterface $trustedOrder = null;

    /**
     * Exact order returned by the native repository save.
     *
     * @var OrderInterface|null
     */
    private ?OrderInterface $savedOrder = null;

    private ?string $savedOrderProof = null;

    /**
     * Execute one replacement-order intent and clear all trust state afterward.
     *
     * @param int $exchangeId
     * @param string $intentHash
     * @param callable $callback
     * @param array<int, array<string, mixed>> $replacementRows
     * @return mixed
     */
    public function execute(
        int $exchangeId,
        string $intentHash,
        callable $callback,
        array $replacementRows = []
    ) {
        $this->activate($exchangeId, $intentHash, $replacementRows);
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
     * Return the server-frozen price for one active replacement row.
     */
    public function getFrozenUnitPrice(int $replacementItemId): ?string
    {
        $row = $this->frozenRows[$replacementItemId] ?? null;

        return is_array($row)
            ? (string)$row[ReplacementItemInterface::UNIT_PRICE_AMOUNT]
            : null;
    }

    /**
     * Return one complete server-frozen row for the active intent.
     *
     * @return array<string, mixed>|null
     */
    public function getFrozenRow(int $replacementItemId): ?array
    {
        return $this->frozenRows[$replacementItemId] ?? null;
    }

    public function getFrozenRowCount(): int
    {
        return count($this->frozenRows);
    }

    /**
     * Register the final validation that must run before native order save.
     */
    public function setPreSubmitValidator(\Closure $validator): void
    {
        if ($this->exchangeId === null
            || $this->intentHash === null
            || $this->preSubmitValidator !== null
        ) {
            throw new InvariantViolationException(
                __('The replacement-order submit validator cannot be registered.')
            );
        }
        $this->preSubmitValidator = $validator;
    }

    /**
     * Register final converted-order validation immediately before core save.
     */
    public function setPrePlaceOrderValidator(\Closure $validator): void
    {
        if ($this->exchangeId === null
            || $this->intentHash === null
            || $this->prePlaceOrderValidator !== null
        ) {
            throw new InvariantViolationException(
                __('The replacement-order place validator cannot be registered.')
            );
        }
        $this->prePlaceOrderValidator = $validator;
    }

    /**
     * Register full native-order validation for the repository result.
     */
    public function setPostSaveOrderValidator(\Closure $validator): void
    {
        if ($this->exchangeId === null
            || $this->intentHash === null
            || $this->postSaveOrderValidator !== null
        ) {
            throw new InvariantViolationException(
                __('The replacement-order save validator cannot be registered.')
            );
        }
        $this->postSaveOrderValidator = $validator;
    }

    /**
     * Revalidate the exact active quote after its final totals collection.
     */
    public function validateBeforeSubmit(Quote $quote): void
    {
        if (!$this->isTrustedQuote($quote)
            || $this->preSubmitValidator === null
            || ($this->submittedQuote !== null
                && $this->submittedQuote !== $quote)
        ) {
            throw new InvariantViolationException(
                __('The replacement quote has no trusted submit validation.')
            );
        }
        ($this->preSubmitValidator)($quote);
        $this->submittedQuote = $quote;
    }

    /**
     * Revalidate the converted native order at the last pre-save boundary.
     */
    public function validateBeforePlace(OrderInterface $order): void
    {
        if ($this->submittedQuote === null
            || $this->prePlaceOrderValidator === null
            || ($this->trustedOrder !== null
                && $this->trustedOrder !== $order)
        ) {
            throw new InvariantViolationException(
                __('The replacement order has no trusted place validation.')
            );
        }
        ($this->prePlaceOrderValidator)($this->submittedQuote, $order);
        $this->trustedOrder = $order;
    }

    /**
     * Determine whether this is the exact converted replacement order.
     */
    public function isTrustedOrder(OrderInterface $order): bool
    {
        return $this->trustedOrder !== null
            && $this->trustedOrder === $order;
    }

    /**
     * Validate the repository result while its outer transaction is active.
     */
    public function validateAfterSave(OrderInterface $order): void
    {
        if (!$this->isTrustedOrder($order)
            || $this->postSaveOrderValidator === null
            || ($this->savedOrder !== null && $this->savedOrder !== $order)
        ) {
            throw new InvariantViolationException(
                __('The replacement order has no trusted save validation.')
            );
        }
        $proof = ($this->postSaveOrderValidator)($order);
        if (!is_string($proof)
            || !preg_match(self::INTENT_HASH_PATTERN, $proof)
        ) {
            throw new InvariantViolationException(
                __('The saved replacement order proof is invalid.')
            );
        }
        $this->savedOrder = $order;
        $this->savedOrderProof = $proof;
    }

    /**
     * Prove repository validation ran and remains valid before commit.
     */
    public function validateBeforeCommit(
        int $orderId,
        OrderInterface $persistedOrder
    ): void
    {
        if ($orderId <= 0
            || $this->savedOrder === null
            || (int)$this->savedOrder->getEntityId() !== $orderId
            || (int)$persistedOrder->getEntityId() !== $orderId
            || $this->savedOrderProof === null
            || $this->postSaveOrderValidator === null
        ) {
            throw new InvariantViolationException(
                __('The replacement order was not validated before commit.')
            );
        }
        $persistedProof = ($this->postSaveOrderValidator)($persistedOrder);
        if (!is_string($persistedProof)
            || !preg_match(self::INTENT_HASH_PATTERN, $persistedProof)
            || !hash_equals($this->savedOrderProof, $persistedProof)
        ) {
            throw new InvariantViolationException(
                __('The persisted replacement order changed before commit.')
            );
        }
    }

    public function hasPrePlaceOrderValidator(): bool
    {
        return $this->prePlaceOrderValidator !== null;
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
        $this->frozenRows = [];
        $this->preSubmitValidator = null;
        $this->prePlaceOrderValidator = null;
        $this->postSaveOrderValidator = null;
        $this->submittedQuote = null;
        $this->trustedOrder = null;
        $this->savedOrder = null;
        $this->savedOrderProof = null;
    }

    /**
     * Validate and activate an intent.
     *
     * @param int $exchangeId
     * @param string $intentHash
     * @param array<int, array<string, mixed>> $replacementRows
     * @return void
     */
    private function activate(
        int $exchangeId,
        string $intentHash,
        array $replacementRows
    ): void {
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

        $frozenRows = [];
        foreach ($replacementRows as $row) {
            $itemId = (int)($row[ReplacementItemInterface::ENTITY_ID] ?? 0);
            $productId = (int)($row[
                ReplacementItemInterface::PRODUCT_ID
            ] ?? 0);
            $sku = $row[ReplacementItemInterface::SKU] ?? null;
            $name = $row[ReplacementItemInterface::NAME] ?? null;
            $qty = $row[ReplacementItemInterface::QTY] ?? null;
            $unitPrice = $row[
                ReplacementItemInterface::UNIT_PRICE_AMOUNT
            ] ?? null;
            $rowTotal = $row[
                ReplacementItemInterface::ROW_TOTAL_AMOUNT
            ] ?? null;
            if ($itemId <= 0
                || $productId <= 0
                || isset($frozenRows[$itemId])
                || !is_string($sku)
                || trim($sku) === ''
                || !is_string($name)
                || trim($name) === ''
                || !is_string($qty)
                || trim($qty) === ''
                || !is_string($unitPrice)
                || trim($unitPrice) === ''
                || !is_string($rowTotal)
                || trim($rowTotal) === ''
            ) {
                throw new InvariantViolationException(
                    __('The replacement-order frozen price context is invalid.')
                );
            }
            $frozenRows[$itemId] = [
                ReplacementItemInterface::ENTITY_ID => $itemId,
                ReplacementItemInterface::PRODUCT_ID => $productId,
                ReplacementItemInterface::SKU => trim($sku),
                ReplacementItemInterface::NAME => $name,
                ReplacementItemInterface::QTY => trim($qty),
                ReplacementItemInterface::UNIT_PRICE_AMOUNT =>
                    trim($unitPrice),
                ReplacementItemInterface::ROW_TOTAL_AMOUNT =>
                    trim($rowTotal),
            ];
        }

        $this->exchangeId = $exchangeId;
        $this->intentHash = $intentHash;
        $this->frozenRows = $frozenRows;
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
