<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model;

use Bonlineco\SalesExchange\Api\BalanceCalculatorInterface;
use Bonlineco\SalesExchange\Api\Data\ExchangeInterface;
use Bonlineco\SalesExchange\Api\History\ActorType;
use Bonlineco\SalesExchange\Api\StateTransitionGuardInterface;
use Bonlineco\SalesExchange\Api\Status\ExchangeStatus;
use Bonlineco\SalesExchange\Api\Status\ReplacementStatus;
use Bonlineco\SalesExchange\Api\Status\ReturnStatus;
use Bonlineco\SalesExchange\Api\Status\SettlementStatus;
use Bonlineco\SalesExchange\Api\Status\StateDimension;
use Bonlineco\SalesExchange\Api\TransitionExchangeInterface;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Creditmemo\ReturnCreditProjection;
use Bonlineco\SalesExchange\Model\ResourceModel\AllocationGuard;
use Bonlineco\SalesExchange\Model\ResourceModel\Exchange as ExchangeResource;
use Bonlineco\SalesExchange\Model\ResourceModel\History as HistoryResource;
use Bonlineco\SalesExchange\Model\ResourceModel\ReplacementItem as ReplacementItemResource;
use Bonlineco\SalesExchange\Model\ResourceModel\ReturnItem as ReturnItemResource;
use Bonlineco\SalesExchange\Model\ResourceModel\Settlement as SettlementResource;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Single transactional writer for exchange workflow status changes.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class TransitionExchange implements TransitionExchangeInterface
{
    private ExchangeFactory $exchangeFactory;

    private ExchangeResource $exchangeResource;

    private HistoryFactory $historyFactory;

    private HistoryResource $historyResource;

    private StateTransitionGuardInterface $transitionGuard;

    private CompletionValidator $completionValidator;

    private ReturnItemResource $returnItemResource;

    private ReplacementItemResource $replacementItemResource;

    private SettlementResource $settlementResource;

    private AllocationGuard $allocationGuard;

    private WorkflowCoordinator $workflowCoordinator;

    private VersionGuard $versionGuard;

    private BalanceCalculatorInterface $balanceCalculator;

    private ReturnCreditProjection $returnCreditProjection;

    private NativeReplacementProjection $nativeReplacementProjection;

    public function __construct(
        ExchangeFactory $exchangeFactory,
        ExchangeResource $exchangeResource,
        HistoryFactory $historyFactory,
        HistoryResource $historyResource,
        StateTransitionGuardInterface $transitionGuard,
        CompletionValidator $completionValidator,
        ReturnItemResource $returnItemResource,
        ReplacementItemResource $replacementItemResource,
        SettlementResource $settlementResource,
        AllocationGuard $allocationGuard,
        WorkflowCoordinator $workflowCoordinator,
        VersionGuard $versionGuard,
        BalanceCalculatorInterface $balanceCalculator,
        ReturnCreditProjection $returnCreditProjection,
        NativeReplacementProjection $nativeReplacementProjection
    ) {
        $this->exchangeFactory = $exchangeFactory;
        $this->exchangeResource = $exchangeResource;
        $this->historyFactory = $historyFactory;
        $this->historyResource = $historyResource;
        $this->transitionGuard = $transitionGuard;
        $this->completionValidator = $completionValidator;
        $this->returnItemResource = $returnItemResource;
        $this->replacementItemResource = $replacementItemResource;
        $this->settlementResource = $settlementResource;
        $this->allocationGuard = $allocationGuard;
        $this->workflowCoordinator = $workflowCoordinator;
        $this->versionGuard = $versionGuard;
        $this->balanceCalculator = $balanceCalculator;
        $this->returnCreditProjection = $returnCreditProjection;
        $this->nativeReplacementProjection = $nativeReplacementProjection;
    }

    /**
     * @inheritdoc
     */
    public function execute(
        int $exchangeId,
        int $expectedVersion,
        string $dimension,
        string $toStatus,
        string $actorType,
        ?int $actorId = null,
        ?string $comment = null
    ): ExchangeInterface {
        $this->validateActor($actorType, $actorId);
        $connection = $this->exchangeResource->getConnection();
        $connection->beginTransaction();
        try {
            $row = $this->exchangeResource->getDataForUpdate($exchangeId);
            if ($row === null) {
                throw new NoSuchEntityException(
                    __('No exchange case exists for ID "%1".', $exchangeId)
                );
            }

            $nextVersion = $this->versionGuard->assertCurrentAndIncrement(
                $expectedVersion,
                (int)$row[ExchangeInterface::VERSION],
                'exchange case'
            );
            $fromStatus = $this->getStatus($row, $dimension);
            $this->transitionGuard->execute($dimension, $fromStatus, $toStatus);
            $exchange = $this->exchangeFactory->create();
            $exchange->setData($row);
            $returnRows = $this->returnItemResource->getRowsByExchangeId($exchangeId);
            $replacementRows = $this->replacementItemResource->getRowsByExchangeId($exchangeId);
            $settlementRows = $this->settlementResource->getRowsByExchangeId($exchangeId);
            $this->workflowCoordinator->execute(
                $exchange,
                $dimension,
                $toStatus,
                $returnRows,
                $replacementRows,
                $settlementRows
            );
            if ($fromStatus === $toStatus) {
                $connection->commit();

                return $exchange;
            }

            $this->validateTarget(
                $exchange,
                $dimension,
                $toStatus,
                $returnRows,
                $replacementRows,
                $settlementRows
            );
            $this->reconcileFrozenTotals(
                $exchange,
                $dimension,
                $toStatus,
                $returnRows,
                $replacementRows
            );
            if ($dimension === StateDimension::EXCHANGE
                && $toStatus === ExchangeStatus::CANCELLED
            ) {
                $this->cancelSubordinateWorkflows(
                    $exchange,
                    $returnRows,
                    $actorType,
                    $actorId,
                    $comment
                );
            }
            $this->setStatus($exchange, $dimension, $toStatus);
            $exchange->setVersion($nextVersion);

            if ($dimension === StateDimension::EXCHANGE && $toStatus === ExchangeStatus::COMPLETED) {
                $this->completionValidator->execute(
                    $exchange,
                    $returnRows,
                    $replacementRows,
                    $settlementRows
                );
            } elseif ($dimension === StateDimension::EXCHANGE
                && $toStatus === ExchangeStatus::REJECTED
            ) {
                $this->completionValidator->assertRejectedOutcome(
                    $exchange,
                    $returnRows,
                    $replacementRows,
                    $settlementRows
                );
            }

            $this->exchangeResource->save($exchange);
            $this->recordHistory(
                $exchangeId,
                $dimension,
                $fromStatus,
                $toStatus,
                $actorType,
                $actorId,
                $comment
            );
            $connection->commit();

            return $exchange;
        } catch (\Throwable $exception) {
            $connection->rollBack();
            if ($exception instanceof LocalizedException) {
                throw $exception;
            }
            throw new CouldNotSaveException(
                __('The exchange workflow status could not be changed.'),
                $exception
            );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $returnRows
     * @param array<int, array<string, mixed>> $replacementRows
     */
    private function reconcileFrozenTotals(
        Exchange $exchange,
        string $dimension,
        string $toStatus,
        array $returnRows,
        array $replacementRows
    ): void {
        $changed = false;
        if ($dimension === StateDimension::RETURN
            && in_array(
                $toStatus,
                [
                    ReturnStatus::ACCEPTED,
                    ReturnStatus::PARTIALLY_ACCEPTED,
                    ReturnStatus::REJECTED,
                ],
                true
            )
        ) {
            $exchange->setReturnCreditAmount(
                $this->completionValidator->getApprovedReturnCredit($returnRows)
            );
            $changed = true;
        } elseif ($dimension === StateDimension::RETURN
            && $toStatus === ReturnStatus::CANCELLED
        ) {
            $exchange->setReturnCreditAmount('0.0000');
            $changed = true;
        }

        if ($dimension === StateDimension::REPLACEMENT
            && $toStatus === ReplacementStatus::READY
        ) {
            $exchange->setReplacementAmount(
                $this->completionValidator->getReadyReplacementAmount($replacementRows)
            );
            $changed = true;
        } elseif ($dimension === StateDimension::REPLACEMENT
            && $toStatus === ReplacementStatus::CANCELLED
        ) {
            if ($exchange->getReplacementStatus() === ReplacementStatus::PENDING) {
                $exchange->setReplacementAmount('0.0000')
                    ->setShippingAmount('0.0000');
            }
            $exchange->setFeeAmount('0.0000');
            $changed = true;
        }

        if ($changed) {
            $effectiveReturnStatus = $dimension === StateDimension::RETURN
                ? $toStatus
                : $exchange->getReturnStatus();
            $effectiveReplacementStatus = $dimension === StateDimension::REPLACEMENT
                ? $toStatus
                : $exchange->getReplacementStatus();
            $this->recalculateBalance(
                $exchange,
                $returnRows,
                $effectiveReturnStatus,
                $effectiveReplacementStatus
            );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $returnRows
     */
    private function recalculateBalance(
        Exchange $exchange,
        array $returnRows,
        string $returnStatus,
        string $replacementStatus
    ): void {
        $effectiveReturnCredit = in_array(
            $returnStatus,
            [ReturnStatus::ACCEPTED, ReturnStatus::PARTIALLY_ACCEPTED],
            true
        )
            ? $this->returnCreditProjection->execute(
                $exchange->getNativeReturnCreditAmount(),
                $returnRows
            )
            : '0.0000';
        $exchange->setBalanceAmount(
            $this->balanceCalculator->execute(
                $this->nativeReplacementProjection->execute(
                    $replacementStatus,
                    $exchange->getReplacementAmount(),
                    $exchange->getShippingAmount(),
                    $exchange->getNativeReplacementAmount()
                ),
                '0.0000',
                $exchange->getFeeAmount(),
                $effectiveReturnCredit
            )
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function getStatus(array $row, string $dimension): string
    {
        $columns = [
            StateDimension::EXCHANGE => ExchangeInterface::EXCHANGE_STATUS,
            StateDimension::RETURN => ExchangeInterface::RETURN_STATUS,
            StateDimension::REPLACEMENT => ExchangeInterface::REPLACEMENT_STATUS,
            StateDimension::SETTLEMENT => ExchangeInterface::SETTLEMENT_STATUS,
        ];
        if (!isset($columns[$dimension])) {
            throw new InvariantViolationException(__('Unknown workflow dimension "%1".', $dimension));
        }

        return (string)$row[$columns[$dimension]];
    }

    private function setStatus(Exchange $exchange, string $dimension, string $status): void
    {
        if ($dimension === StateDimension::EXCHANGE) {
            $exchange->setExchangeStatus($status);
        } elseif ($dimension === StateDimension::RETURN) {
            $exchange->setReturnStatus($status);
        } elseif ($dimension === StateDimension::REPLACEMENT) {
            $exchange->setReplacementStatus($status);
        } else {
            $exchange->setSettlementStatus($status);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $returnRows
     * @param array<int, array<string, mixed>> $replacementRows
     * @param array<int, array<string, mixed>> $settlementRows
     */
    private function validateTarget(
        Exchange $exchange,
        string $dimension,
        string $toStatus,
        array $returnRows,
        array $replacementRows,
        array $settlementRows
    ): void {
        if ($dimension === StateDimension::RETURN) {
            foreach ($returnRows as $row) {
                $this->allocationGuard->lock((int)$row['order_item_id']);
            }
            if ($toStatus === ReturnStatus::AUTHORIZED) {
                $this->completionValidator->assertReturnAuthorization($returnRows);
            } elseif ($toStatus === ReturnStatus::RECEIVED) {
                $this->completionValidator->assertReturnReceipt($returnRows);
            } elseif (in_array(
                $toStatus,
                [
                    ReturnStatus::INSPECTED,
                    ReturnStatus::ACCEPTED,
                    ReturnStatus::PARTIALLY_ACCEPTED,
                    ReturnStatus::REJECTED,
                ],
                true
            )) {
                $this->completionValidator->assertReturnState($toStatus, $returnRows);
            }
        }
        if ($dimension === StateDimension::REPLACEMENT
            && $toStatus === ReplacementStatus::DELIVERED
        ) {
            $this->completionValidator->assertReplacementState($exchange, $replacementRows);
        }
        if ($dimension === StateDimension::SETTLEMENT
            && in_array($toStatus, SettlementStatus::terminal(), true)
        ) {
            $this->completionValidator->assertFinancialTotals(
                $exchange,
                $returnRows,
                $replacementRows
            );
            $this->completionValidator->assertSettlementState($exchange, $settlementRows, $toStatus);
        }
    }

    private function cancelSubordinateWorkflows(
        Exchange $exchange,
        array $returnRows,
        string $actorType,
        ?int $actorId,
        ?string $comment
    ): void {
        $replacementWasPending = $exchange->getReplacementStatus()
            === ReplacementStatus::PENDING;
        $targets = [
            StateDimension::RETURN => ReturnStatus::CANCELLED,
            StateDimension::REPLACEMENT => ReplacementStatus::CANCELLED,
            StateDimension::SETTLEMENT => SettlementStatus::CANCELLED,
        ];
        foreach ($targets as $dimension => $toStatus) {
            $fromStatus = $dimension === StateDimension::RETURN
                ? $exchange->getReturnStatus()
                : ($dimension === StateDimension::REPLACEMENT
                    ? $exchange->getReplacementStatus()
                    : $exchange->getSettlementStatus());
            if ($fromStatus === $toStatus) {
                continue;
            }
            $this->transitionGuard->execute($dimension, $fromStatus, $toStatus);
            $this->setStatus($exchange, $dimension, $toStatus);
            $this->recordHistory(
                (int)$exchange->getEntityId(),
                $dimension,
                $fromStatus,
                $toStatus,
                $actorType,
                $actorId,
                $comment
            );
        }
        $exchange->setReturnCreditAmount('0.0000')
            ->setFeeAmount('0.0000');
        if ($replacementWasPending) {
            $exchange->setReplacementAmount('0.0000')
                ->setShippingAmount('0.0000');
        }
        $this->recalculateBalance(
            $exchange,
            $returnRows,
            ReturnStatus::CANCELLED,
            ReplacementStatus::CANCELLED
        );
    }

    private function recordHistory(
        int $exchangeId,
        string $dimension,
        string $fromStatus,
        string $toStatus,
        string $actorType,
        ?int $actorId,
        ?string $comment
    ): void {
        $history = $this->historyFactory->create();
        $history->setExchangeId($exchangeId)
            ->setAction('status_changed')
            ->setStatusDimension($dimension)
            ->setFromValue($fromStatus)
            ->setToValue($toStatus)
            ->setActorType($actorType)
            ->setActorId($actorId)
            ->setComment($comment);
        $this->historyResource->save($history);
    }

    private function validateActor(string $actorType, ?int $actorId): void
    {
        if (!in_array($actorType, ActorType::all(), true)) {
            throw new InvariantViolationException(__('Unknown audit actor type "%1".', $actorType));
        }
        if ($actorId !== null && $actorId <= 0) {
            throw new InvariantViolationException(__('Audit actor ID must be positive when provided.'));
        }
    }
}
