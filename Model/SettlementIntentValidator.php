<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model;

use Bonlineco\SalesExchange\Api\Data\SettlementInterface;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;

/**
 * Compare an idempotent settlement retry with its persisted financial intent.
 */
class SettlementIntentValidator
{
    private DecimalMath $decimalMath;

    public function __construct(DecimalMath $decimalMath)
    {
        $this->decimalMath = $decimalMath;
    }

    public function execute(
        SettlementInterface $requested,
        SettlementInterface $persisted
    ): void {
        $requestedReference = $this->normalizeReference(
            $requested->getExternalReference()
        );
        $persistedReference = $this->normalizeReference(
            $persisted->getExternalReference()
        );
        $matches = $requested->getExchangeId() === $persisted->getExchangeId()
            && $requested->getType() === $persisted->getType()
            && $this->decimalMath->compare(
                $requested->getAmount(),
                $persisted->getAmount()
            ) === 0
            && $requested->getCurrencyCode() === $persisted->getCurrencyCode()
            && $requested->getIdempotencyKey()
                === $persisted->getIdempotencyKey()
            && $requestedReference === $persistedReference;
        if (!$matches) {
            throw new InvariantViolationException(
                __('The idempotency key is already associated with a different settlement intent.')
            );
        }
    }

    private function normalizeReference(?string $reference): ?string
    {
        if ($reference === null) {
            return null;
        }
        $reference = trim($reference);

        return $reference === '' ? null : $reference;
    }
}
