<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\Settlement;

/**
 * Immutable normalized settlement plan.
 */
class Plan
{
    /**
     * @param array<int, array{
     *     type: string,
     *     amount: string,
     *     idempotency_key: string,
     *     external_reference: string|null
     * }> $entries
     */
    public function __construct(
        private int $exchangeId,
        private bool $cancelledReplacement,
        private string $returnCreditAmount,
        private string $replacementAmount,
        private string $baseReplacementAmount,
        private string $balanceAmount,
        private string $currencyCode,
        private string $baseCurrencyCode,
        private string $targetStatus,
        private array $entries
    ) {
    }

    public function getExchangeId(): int
    {
        return $this->exchangeId;
    }

    public function isCancelledReplacement(): bool
    {
        return $this->cancelledReplacement;
    }

    public function requiresInvoice(): bool
    {
        return !$this->cancelledReplacement;
    }

    public function getReturnCreditAmount(): string
    {
        return $this->returnCreditAmount;
    }

    public function getReplacementAmount(): string
    {
        return $this->replacementAmount;
    }

    public function getBaseReplacementAmount(): string
    {
        return $this->baseReplacementAmount;
    }

    public function getBalanceAmount(): string
    {
        return $this->balanceAmount;
    }

    public function getCurrencyCode(): string
    {
        return $this->currencyCode;
    }

    public function getBaseCurrencyCode(): string
    {
        return $this->baseCurrencyCode;
    }

    public function getTargetStatus(): string
    {
        return $this->targetStatus;
    }

    /**
     * @return array<int, array{
     *     type: string,
     *     amount: string,
     *     idempotency_key: string,
     *     external_reference: string|null
     * }>
     */
    public function getEntries(): array
    {
        return $this->entries;
    }
}
