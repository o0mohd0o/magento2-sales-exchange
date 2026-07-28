<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\ReplacementOrder;

use Bonlineco\SalesExchange\Api\Data\ExchangeInterface;
use Bonlineco\SalesExchange\Api\Data\HistoryInterface;
use Bonlineco\SalesExchange\Api\History\ActorType;
use Bonlineco\SalesExchange\Api\StateTransitionGuardInterface;
use Bonlineco\SalesExchange\Api\Status\ExchangeStatus;
use Bonlineco\SalesExchange\Api\Status\ReplacementStatus;
use Bonlineco\SalesExchange\Api\Status\SettlementStatus;
use Bonlineco\SalesExchange\Api\Status\StateDimension;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\CompletionValidator;
use Bonlineco\SalesExchange\Model\HistoryFactory;
use Bonlineco\SalesExchange\Model\ResourceModel\Exchange as ExchangeResource;
use Bonlineco\SalesExchange\Model\ResourceModel\History as HistoryResource;
use Bonlineco\SalesExchange\Model\VersionGuard;

/**
 * Persist proven delivery and close an already-settled exchange when eligible.
 */
class NativeDeliveryWriter
{
    private ExchangeResource $exchangeResource;

    private HistoryFactory $historyFactory;

    private HistoryResource $historyResource;

    private VersionGuard $versionGuard;

    private StateTransitionGuardInterface $transitionGuard;

    private CompletionValidator $completionValidator;

    public function __construct(
        ExchangeResource $exchangeResource,
        HistoryFactory $historyFactory,
        HistoryResource $historyResource,
        VersionGuard $versionGuard,
        StateTransitionGuardInterface $transitionGuard,
        CompletionValidator $completionValidator
    ) {
        $this->exchangeResource = $exchangeResource;
        $this->historyFactory = $historyFactory;
        $this->historyResource = $historyResource;
        $this->versionGuard = $versionGuard;
        $this->transitionGuard = $transitionGuard;
        $this->completionValidator = $completionValidator;
    }

    /**
     * @param array<string, mixed> $exchangeRow
     * @param array<int, array<string, mixed>> $returnRows
     * @param array<int, array<string, mixed>> $replacementRows
     * @param array<int, array<string, mixed>> $settlementRows
     * @return array{exchange: ExchangeInterface, changed: bool}
     */
    public function execute(
        ExchangeInterface $exchange,
        array $exchangeRow,
        array $returnRows,
        array $replacementRows,
        array $settlementRows,
        string $proof
    ): array {
        $proofValue = sprintf(
            '%s;proof=%s',
            ReplacementStatus::DELIVERED,
            $proof
        );
        $deliveryRows = $this->getDeliveryHistoryRows(
            (int)$exchange->getEntityId()
        );
        if ($exchange->getReplacementStatus()
            === ReplacementStatus::DELIVERED
        ) {
            if (count($deliveryRows) !== 1
                || !is_string($deliveryRows[0][
                    HistoryInterface::TO_VALUE
                ] ?? null)
                || !hash_equals(
                    $proofValue,
                    (string)$deliveryRows[0][HistoryInterface::TO_VALUE]
                )
                || (string)($deliveryRows[0][
                    HistoryInterface::ACTOR_TYPE
                ] ?? '') !== ActorType::INTEGRATION
            ) {
                throw new InvariantViolationException(
                    __(
                        'The replayed delivery proof differs from the '
                        . 'immutable delivery audit.'
                    )
                );
            }

            return ['exchange' => $exchange, 'changed' => false];
        }
        if ($deliveryRows !== []) {
            throw new InvariantViolationException(
                __('A delivery audit exists before the replacement is delivered.')
            );
        }

        $nextVersion = $this->versionGuard->assertCurrentAndIncrement(
            (int)$exchangeRow[ExchangeInterface::VERSION],
            (int)$exchangeRow[ExchangeInterface::VERSION],
            'exchange case'
        );
        $fromExchangeStatus = $exchange->getExchangeStatus();
        $exchange->setReplacementStatus(ReplacementStatus::DELIVERED)
            ->setVersion($nextVersion);
        if (in_array(
            $exchange->getSettlementStatus(),
            [
                SettlementStatus::BALANCED,
                SettlementStatus::PAYMENT_RECEIVED,
                SettlementStatus::REFUND_ISSUED,
            ],
            true
        )) {
            $this->completionValidator->execute(
                $exchange,
                $returnRows,
                $replacementRows,
                $settlementRows
            );
            $this->transitionGuard->execute(
                StateDimension::EXCHANGE,
                $fromExchangeStatus,
                ExchangeStatus::COMPLETED
            );
            $exchange->setExchangeStatus(ExchangeStatus::COMPLETED);
        }
        $this->exchangeResource->save($exchange);
        $this->recordHistory(
            $exchange,
            $proofValue,
            $fromExchangeStatus
        );

        return ['exchange' => $exchange, 'changed' => true];
    }

    private function recordHistory(
        ExchangeInterface $exchange,
        string $proofValue,
        string $fromExchangeStatus
    ): void {
        $history = $this->historyFactory->create();
        $history->setExchangeId((int)$exchange->getEntityId())
            ->setAction('replacement_delivery_proven')
            ->setStatusDimension(StateDimension::REPLACEMENT)
            ->setFromValue(ReplacementStatus::SHIPPED)
            ->setToValue($proofValue)
            ->setActorType(ActorType::INTEGRATION)
            ->setActorId(null)
            ->setComment(
                'Synchronized from the configured delivery-proof adapter.'
            );
        $this->historyResource->save($history);

        if ($fromExchangeStatus !== $exchange->getExchangeStatus()) {
            $completion = $this->historyFactory->create();
            $completion->setExchangeId((int)$exchange->getEntityId())
                ->setAction('exchange_completed')
                ->setStatusDimension(StateDimension::EXCHANGE)
                ->setFromValue($fromExchangeStatus)
                ->setToValue($exchange->getExchangeStatus())
                ->setActorType(ActorType::INTEGRATION)
                ->setActorId(null)
                ->setComment(
                    'Completed after trusted replacement delivery proof.'
                );
            $this->historyResource->save($completion);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getDeliveryHistoryRows(int $exchangeId): array
    {
        $matches = [];
        foreach ($this->historyResource->getRowsByExchangeIdForUpdate(
            $exchangeId
        ) as $row) {
            if ((string)($row[HistoryInterface::ACTION] ?? '')
                === 'replacement_delivery_proven'
            ) {
                $matches[] = $row;
            }
        }

        return $matches;
    }
}
