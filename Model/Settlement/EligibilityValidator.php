<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\Settlement;

use Bonlineco\SalesExchange\Api\ConfigInterface;
use Bonlineco\SalesExchange\Api\Data\DocumentLinkInterface;
use Bonlineco\SalesExchange\Api\Data\ExchangeInterface;
use Bonlineco\SalesExchange\Api\Data\ReplacementItemInterface;
use Bonlineco\SalesExchange\Api\DocumentType;
use Bonlineco\SalesExchange\Api\Status\ExchangeStatus;
use Bonlineco\SalesExchange\Api\Status\ReplacementStatus;
use Bonlineco\SalesExchange\Api\Status\ReturnStatus;
use Bonlineco\SalesExchange\Api\Status\SettlementStatus;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;

/**
 * Guard the supported settlement workflow before any new native side effect.
 */
class EligibilityValidator
{
    private ConfigInterface $config;

    private DecimalMath $moneyMath;

    private OperationKeys $operationKeys;

    public function __construct(
        ConfigInterface $config,
        DecimalMath $moneyMath,
        OperationKeys $operationKeys
    ) {
        $this->config = $config;
        $this->moneyMath = $moneyMath;
        $this->operationKeys = $operationKeys;
    }

    /**
     * @param array<int, array<string, mixed>> $returnRows
     * @param array<int, array<string, mixed>> $replacementRows
     * @param array<int, array<string, mixed>> $documentRows
     */
    public function execute(
        ExchangeInterface $exchange,
        array $returnRows,
        array $replacementRows,
        array $documentRows,
        bool $requireEnabled
    ): void {
        if ($requireEnabled && !$this->config->isEnabled($exchange->getStoreId())) {
            throw new InvariantViolationException(
                __('Sales exchanges are disabled for the original order store.')
            );
        }
        if ($exchange->getExchangeStatus() !== ExchangeStatus::IN_PROGRESS
            || !in_array(
                $exchange->getReturnStatus(),
                [ReturnStatus::ACCEPTED, ReturnStatus::PARTIALLY_ACCEPTED],
                true
            )
            || $exchange->getSettlementStatus() !== SettlementStatus::PENDING
            || $returnRows === []
        ) {
            throw new InvariantViolationException(
                __(
                    'Settlement requires an in-progress exchange, a finalized '
                    . 'accepted return, and pending settlement.'
                )
            );
        }
        if ($this->moneyMath->compare($exchange->getFeeAmount(), '0') !== 0) {
            throw new InvariantViolationException(
                __('The first settlement release does not support exchange fees.')
            );
        }

        if ($exchange->getReplacementStatus() === ReplacementStatus::CANCELLED) {
            $this->assertCancelledReplacement(
                $exchange,
                $replacementRows,
                $documentRows
            );

            return;
        }
        if (!in_array(
            $exchange->getReplacementStatus(),
            [
                ReplacementStatus::ORDERED,
                ReplacementStatus::SHIPPED,
                ReplacementStatus::DELIVERED,
            ],
            true
        )) {
            throw new InvariantViolationException(
                __('Settlement requires a native replacement order; a ready intent is not enough.')
            );
        }
        $this->assertActiveDocuments($exchange, $documentRows);
    }

    /**
     * Validate an already reconciled terminal settlement without a config gate.
     *
     * @param array<int, array<string, mixed>> $returnRows
     * @param array<int, array<string, mixed>> $replacementRows
     * @param array<int, array<string, mixed>> $documentRows
     */
    public function assertTerminal(
        ExchangeInterface $exchange,
        array $returnRows,
        array $replacementRows,
        array $documentRows
    ): void {
        if (!in_array(
            $exchange->getExchangeStatus(),
            [ExchangeStatus::IN_PROGRESS, ExchangeStatus::COMPLETED],
            true
        ) || !in_array(
            $exchange->getReturnStatus(),
            [ReturnStatus::ACCEPTED, ReturnStatus::PARTIALLY_ACCEPTED],
            true
        ) || !in_array(
            $exchange->getSettlementStatus(),
            [
                SettlementStatus::BALANCED,
                SettlementStatus::PAYMENT_RECEIVED,
                SettlementStatus::REFUND_ISSUED,
            ],
            true
        ) || $returnRows === []
        ) {
            throw new InvariantViolationException(
                __('The reconciled settlement workflow state is invalid.')
            );
        }
        if ($this->moneyMath->compare($exchange->getFeeAmount(), '0') !== 0) {
            throw new InvariantViolationException(
                __('The first settlement release does not support exchange fees.')
            );
        }
        if ($exchange->getReplacementStatus() === ReplacementStatus::CANCELLED) {
            $this->assertCancelledReplacement(
                $exchange,
                $replacementRows,
                $documentRows
            );

            return;
        }
        if (!in_array(
            $exchange->getReplacementStatus(),
            [
                ReplacementStatus::ORDERED,
                ReplacementStatus::SHIPPED,
                ReplacementStatus::DELIVERED,
            ],
            true
        )) {
            throw new InvariantViolationException(
                __('The reconciled settlement has no valid replacement outcome.')
            );
        }
        $this->assertActiveDocuments($exchange, $documentRows);
    }

    /**
     * @param array<int, array<string, mixed>> $replacementRows
     * @param array<int, array<string, mixed>> $documentRows
     */
    private function assertCancelledReplacement(
        ExchangeInterface $exchange,
        array $replacementRows,
        array $documentRows
    ): void {
        if ($this->moneyMath->compare(
            $exchange->getNativeReplacementAmount(),
            '0'
        ) !== 0 || $this->moneyMath->compare(
            $exchange->getBaseNativeReplacementAmount(),
            '0'
        ) !== 0) {
            throw new InvariantViolationException(
                __('A cancelled replacement cannot retain native order totals.')
            );
        }
        foreach ($replacementRows as $row) {
            if (($row[ReplacementItemInterface::REPLACEMENT_ORDER_ITEM_ID] ?? null)
                !== null
            ) {
                throw new InvariantViolationException(
                    __('A cancelled replacement cannot retain native order item links.')
                );
            }
        }
        $orderRows = [];
        foreach ($documentRows as $row) {
            $type = (string)($row[
                DocumentLinkInterface::DOCUMENT_TYPE
            ] ?? '');
            if ($type === DocumentType::ORDER) {
                $orderRows[] = $row;
            } elseif (in_array(
                $type,
                [DocumentType::INVOICE, DocumentType::SHIPMENT],
                true
            )) {
                throw new InvariantViolationException(
                    __('A cancelled replacement cannot retain native commercial documents.')
                );
            }
        }
        if (count($orderRows) > 1
            || ($orderRows !== []
                && (string)$orderRows[0][
                    DocumentLinkInterface::OPERATION_KEY
                ] !== $this->operationKeys->replacementOrder(
                    (int)$exchange->getEntityId()
                ))
        ) {
            throw new InvariantViolationException(
                __(
                    'A cancelled replacement can retain only its one trusted '
                    . 'immutable order audit link.'
                )
            );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $documentRows
     */
    private function assertActiveDocuments(
        ExchangeInterface $exchange,
        array $documentRows
    ): void {
        $orderRows = [];
        $invoiceRows = [];
        foreach ($documentRows as $row) {
            $type = (string)($row[DocumentLinkInterface::DOCUMENT_TYPE] ?? '');
            if ($type === DocumentType::ORDER) {
                $orderRows[] = $row;
            } elseif ($type === DocumentType::INVOICE) {
                $invoiceRows[] = $row;
            }
        }
        if (count($orderRows) !== 1
            || (string)$orderRows[0][DocumentLinkInterface::OPERATION_KEY]
                !== $this->operationKeys->replacementOrder(
                    (int)$exchange->getEntityId()
                )
        ) {
            throw new InvariantViolationException(
                __('Settlement requires exactly one trusted replacement order link.')
            );
        }
        if (count($invoiceRows) > 1) {
            throw new InvariantViolationException(
                __('An exchange cannot be linked to more than one replacement invoice.')
            );
        }
        if ($invoiceRows !== []
            && (string)$invoiceRows[0][DocumentLinkInterface::OPERATION_KEY]
                !== $this->operationKeys->invoice((int)$exchange->getEntityId())
        ) {
            throw new InvariantViolationException(
                __('The replacement invoice link belongs to another settlement intent.')
            );
        }
    }
}
