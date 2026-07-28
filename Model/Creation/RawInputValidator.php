<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\Creation;

use Bonlineco\SalesExchange\Exception\InvariantViolationException;

/**
 * Reject oversized raw create payloads before DTO construction or persistence.
 */
class RawInputValidator
{
    public const MAX_LINES = 100;
    public const MAX_NOTE_LENGTH = 4000;

    /**
     * @param mixed $returnRows
     * @param mixed $replacementRows
     * @param mixed $customerNote
     * @param mixed $internalNote
     */
    public function execute(
        $returnRows,
        $replacementRows,
        $customerNote,
        $internalNote
    ): void {
        if (!is_array($returnRows) || !is_array($replacementRows)) {
            throw new InvariantViolationException(
                __('The submitted exchange lines are invalid.')
            );
        }

        // Both counts are checked before either array is iterated.
        if (count($returnRows) > self::MAX_LINES
            || count($replacementRows) > self::MAX_LINES
        ) {
            throw new InvariantViolationException(
                __('An exchange cannot contain more than %1 lines of either type.', self::MAX_LINES)
            );
        }

        foreach ($returnRows as $row) {
            $this->assertRow($row, ['selected', 'order_item_id', 'qty', 'reason_code']);
        }
        foreach ($replacementRows as $row) {
            $this->assertRow($row, ['sku', 'qty']);
        }
        foreach ([$customerNote, $internalNote] as $note) {
            if ($note !== null && !is_scalar($note)) {
                throw new InvariantViolationException(__('The submitted exchange notes are invalid.'));
            }
            if ($note !== null && mb_strlen((string)$note) > self::MAX_NOTE_LENGTH) {
                throw new InvariantViolationException(
                    __('Exchange notes cannot exceed %1 characters.', self::MAX_NOTE_LENGTH)
                );
            }
        }
    }

    /**
     * @param mixed $row
     * @param string[] $fields
     */
    private function assertRow($row, array $fields): void
    {
        if (!is_array($row)) {
            throw new InvariantViolationException(__('A submitted exchange line is invalid.'));
        }
        foreach ($fields as $field) {
            if (isset($row[$field]) && !is_scalar($row[$field])) {
                throw new InvariantViolationException(__('A submitted exchange line is invalid.'));
            }
        }
    }
}
