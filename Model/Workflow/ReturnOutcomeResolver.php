<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\Workflow;

use Bonlineco\SalesExchange\Api\Data\ReturnItemInterface;
use Bonlineco\SalesExchange\Api\Status\ReturnStatus;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;

/**
 * Derive the return terminal state exclusively from persisted inspection rows.
 */
class ReturnOutcomeResolver
{
    private DecimalMath $quantityMath;

    public function __construct(DecimalMath $quantityMath)
    {
        $this->quantityMath = $quantityMath;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function execute(array $rows): string
    {
        $accepted = '0.0000';
        $rejected = '0.0000';
        $received = '0.0000';
        foreach ($rows as $row) {
            if ((int)($row[ReturnItemInterface::RECEIPT_RESOLVED] ?? 0) !== 1) {
                throw new InvariantViolationException(
                    __('Every return line must have a resolved warehouse receipt.')
                );
            }
            $rowReceived = (string)($row[ReturnItemInterface::RECEIVED_QTY] ?? '');
            $rowAccepted = (string)($row[ReturnItemInterface::ACCEPTED_QTY] ?? '');
            $rowRejected = (string)($row[ReturnItemInterface::REJECTED_QTY] ?? '');
            if ($this->quantityMath->compare(
                $this->quantityMath->add($rowAccepted, $rowRejected),
                $rowReceived
            ) !== 0) {
                throw new InvariantViolationException(
                    __('Every received unit must be inspected before finalizing the return.')
                );
            }
            $received = $this->quantityMath->add($received, $rowReceived);
            $accepted = $this->quantityMath->add($accepted, $rowAccepted);
            $rejected = $this->quantityMath->add($rejected, $rowRejected);
        }
        if ($this->quantityMath->compare($received, '0') <= 0) {
            throw new InvariantViolationException(
                __('At least one received unit is required to finalize inspection.')
            );
        }
        if ($this->quantityMath->compare($accepted, '0') === 0) {
            return ReturnStatus::REJECTED;
        }
        if ($this->quantityMath->compare($rejected, '0') === 0) {
            return ReturnStatus::ACCEPTED;
        }

        return ReturnStatus::PARTIALLY_ACCEPTED;
    }
}
