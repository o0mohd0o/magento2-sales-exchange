<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\Creation;

use Bonlineco\SalesExchange\Api\Data\CreateExchangeRequestInterface;
use Bonlineco\SalesExchange\Api\Data\ReplacementSelectionInterface;
use Bonlineco\SalesExchange\Api\Data\ReturnSelectionInterface;
use Bonlineco\SalesExchange\Api\ReasonCode;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;

/**
 * Pure structural validation for draft creation input.
 */
class CreateInputValidator
{
    private DecimalMath $quantityMath;

    public function __construct(DecimalMath $quantityMath)
    {
        $this->quantityMath = $quantityMath;
    }

    /**
     * @param string[] $allowedReasonCodes
     */
    public function execute(
        CreateExchangeRequestInterface $request,
        array $allowedReasonCodes
    ): void {
        if ($request->getOrderId() <= 0 || $request->getActorId() <= 0) {
            throw new InvariantViolationException(
                __('A valid original order and admin actor are required.')
            );
        }
        $returnItems = $request->getReturnItems();
        $replacementItems = $request->getReplacementItems();
        if ($returnItems === [] || $replacementItems === []) {
            throw new InvariantViolationException(
                __('Select at least one returned item and one replacement item.')
            );
        }
        if (count($returnItems) > RawInputValidator::MAX_LINES
            || count($replacementItems) > RawInputValidator::MAX_LINES
        ) {
            throw new InvariantViolationException(
                __(
                    'An exchange cannot contain more than %1 lines of either type.',
                    RawInputValidator::MAX_LINES
                )
            );
        }

        $seenOrderItems = [];
        foreach ($returnItems as $item) {
            if (!$item instanceof ReturnSelectionInterface || $item->getOrderItemId() <= 0) {
                throw new InvariantViolationException(__('A selected return line is invalid.'));
            }
            if (isset($seenOrderItems[$item->getOrderItemId()])) {
                throw new InvariantViolationException(
                    __('Each original order item can only be selected once.')
                );
            }
            $seenOrderItems[$item->getOrderItemId()] = true;
            $this->assertPositiveQuantity($item->getQuantity());
            if (!in_array($item->getReasonCode(), ReasonCode::all(), true)
                || !in_array($item->getReasonCode(), $allowedReasonCodes, true)
            ) {
                throw new InvariantViolationException(
                    __('The selected return reason is not allowed.')
                );
            }
        }

        $seenSkus = [];
        foreach ($replacementItems as $item) {
            if (!$item instanceof ReplacementSelectionInterface) {
                throw new InvariantViolationException(__('A selected replacement line is invalid.'));
            }
            $sku = strtolower(trim($item->getSku()));
            if ($sku === '') {
                throw new InvariantViolationException(__('Every replacement line requires a SKU.'));
            }
            if (isset($seenSkus[$sku])) {
                throw new InvariantViolationException(
                    __('Combine duplicate replacement SKUs into one quantity.')
                );
            }
            $seenSkus[$sku] = true;
            $this->assertPositiveQuantity($item->getQuantity());
        }

        foreach ([$request->getCustomerNote(), $request->getInternalNote()] as $note) {
            if ($note !== null && mb_strlen($note) > RawInputValidator::MAX_NOTE_LENGTH) {
                throw new InvariantViolationException(
                    __(
                        'Exchange notes cannot exceed %1 characters.',
                        RawInputValidator::MAX_NOTE_LENGTH
                    )
                );
            }
        }
    }

    private function assertPositiveQuantity(string $quantity): void
    {
        $normalized = $this->quantityMath->assertNonNegative(
            $quantity,
            'Selected quantity'
        );
        if ($this->quantityMath->compare($normalized, '0') <= 0) {
            throw new InvariantViolationException(
                __('Selected quantities must be greater than zero.')
            );
        }
    }
}
