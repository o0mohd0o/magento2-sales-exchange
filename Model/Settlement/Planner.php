<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\Settlement;

use Bonlineco\SalesExchange\Api\Data\ExchangeInterface;
use Bonlineco\SalesExchange\Api\Settlement\Type;
use Bonlineco\SalesExchange\Api\Status\ReplacementStatus;
use Bonlineco\SalesExchange\Api\Status\SettlementStatus;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;

/**
 * Convert native totals into the canonical return-credit and cash ledger.
 */
class Planner
{
    private DecimalMath $moneyMath;

    private OperationKeys $operationKeys;

    public function __construct(
        DecimalMath $moneyMath,
        OperationKeys $operationKeys
    ) {
        $this->moneyMath = $moneyMath;
        $this->operationKeys = $operationKeys;
    }

    public function execute(
        ExchangeInterface $exchange,
        ?string $externalReference
    ): Plan {
        $exchangeId = (int)$exchange->getEntityId();
        if ($exchangeId <= 0) {
            throw new InvariantViolationException(
                __('A persisted exchange case is required for settlement.')
            );
        }
        $reference = $this->normalizeReference($externalReference);
        $returnCredit = $this->moneyMath->assertNonNegative(
            $exchange->getNativeReturnCreditAmount(),
            'Native return credit amount'
        );
        $cancelled = $exchange->getReplacementStatus()
            === ReplacementStatus::CANCELLED;
        $replacement = $cancelled
            ? '0.0000'
            : $this->moneyMath->assertNonNegative(
                $exchange->getNativeReplacementAmount(),
                'Native replacement amount'
            );
        $baseReplacement = $cancelled
            ? '0.0000'
            : $this->moneyMath->assertNonNegative(
                $exchange->getBaseNativeReplacementAmount(),
                'Base native replacement amount'
            );
        $balance = $this->moneyMath->subtract($replacement, $returnCredit);
        if ($this->moneyMath->compare($balance, $exchange->getBalanceAmount()) !== 0) {
            throw new InvariantViolationException(
                __('The persisted exchange balance does not match the native settlement totals.')
            );
        }

        $entries = [];
        if ($this->moneyMath->compare($returnCredit, '0') > 0) {
            $entries[] = [
                'type' => Type::RETURN_CREDIT,
                'amount' => $returnCredit,
                'idempotency_key' => $this->operationKeys->returnCredit($exchangeId),
                'external_reference' => null,
            ];
        }

        $comparison = $this->moneyMath->compare($balance, '0');
        if ($comparison === 0) {
            if ($reference !== null) {
                throw new InvariantViolationException(
                    __('A balanced settlement cannot include an external cash reference.')
                );
            }
            $targetStatus = SettlementStatus::BALANCED;
        } elseif ($comparison > 0) {
            if ($cancelled) {
                throw new InvariantViolationException(
                    __('A cancelled replacement cannot produce a customer payment.')
                );
            }
            $this->requireReference($reference);
            $entries[] = [
                'type' => Type::CUSTOMER_PAYMENT,
                'amount' => $balance,
                'idempotency_key' => $this->operationKeys->customerPayment($exchangeId),
                'external_reference' => $reference,
            ];
            $targetStatus = SettlementStatus::PAYMENT_RECEIVED;
        } else {
            $this->requireReference($reference);
            $entries[] = [
                'type' => Type::MERCHANT_REFUND,
                'amount' => $balance,
                'idempotency_key' => $this->operationKeys->merchantRefund($exchangeId),
                'external_reference' => $reference,
            ];
            $targetStatus = SettlementStatus::REFUND_ISSUED;
        }

        return new Plan(
            $exchangeId,
            $cancelled,
            $returnCredit,
            $replacement,
            $baseReplacement,
            $balance,
            $exchange->getCurrencyCode(),
            $exchange->getBaseCurrencyCode(),
            $targetStatus,
            $entries
        );
    }

    private function normalizeReference(?string $externalReference): ?string
    {
        if ($externalReference === null) {
            return null;
        }
        $externalReference = trim($externalReference);
        if (mb_strlen($externalReference) > 255) {
            throw new InvariantViolationException(
                __('The external settlement reference cannot exceed 255 characters.')
            );
        }

        return $externalReference === '' ? null : $externalReference;
    }

    private function requireReference(?string $reference): void
    {
        if ($reference === null) {
            throw new InvariantViolationException(
                __('A customer payment or merchant refund requires an external reference.')
            );
        }
    }
}
