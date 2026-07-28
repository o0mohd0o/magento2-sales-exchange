<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\ReplacementOrder;

use Bonlineco\SalesExchange\Api\BalanceCalculatorInterface;
use Bonlineco\SalesExchange\Api\CancelReplacementIntentInterface;
use Bonlineco\SalesExchange\Api\Data\DocumentLinkInterface;
use Bonlineco\SalesExchange\Api\Data\ExchangeInterface;
use Bonlineco\SalesExchange\Api\Data\ReplacementItemInterface;
use Bonlineco\SalesExchange\Api\DocumentType;
use Bonlineco\SalesExchange\Api\ExchangeRepositoryInterface;
use Bonlineco\SalesExchange\Api\History\ActorType;
use Bonlineco\SalesExchange\Api\StateTransitionGuardInterface;
use Bonlineco\SalesExchange\Api\Status\ReplacementStatus;
use Bonlineco\SalesExchange\Api\Status\ReturnStatus;
use Bonlineco\SalesExchange\Api\Status\StateDimension;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Creditmemo\ReturnCreditProjection;
use Bonlineco\SalesExchange\Model\Exchange;
use Bonlineco\SalesExchange\Model\ExchangeFactory;
use Bonlineco\SalesExchange\Model\FinancialAggregateCalculator;
use Bonlineco\SalesExchange\Model\HistoryFactory;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Bonlineco\SalesExchange\Model\ResourceModel\DocumentLink as DocumentLinkResource;
use Bonlineco\SalesExchange\Model\ResourceModel\Exchange as ExchangeResource;
use Bonlineco\SalesExchange\Model\ResourceModel\History as HistoryResource;
use Bonlineco\SalesExchange\Model\ResourceModel\ReplacementItem as ReplacementItemResource;
use Bonlineco\SalesExchange\Model\ResourceModel\ReturnItem as ReturnItemResource;
use Bonlineco\SalesExchange\Model\VersionGuard;
use Bonlineco\SalesExchange\Model\WorkflowCoordinator;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\OrderMutexInterface;

/**
 * Mutex-protected compensation for a replacement that has no native order.
 *
 * Generic workflow transitions intentionally cannot perform READY -> CANCELLED:
 * this command first proves the absence of both a Magento order and every
 * durable module-side handoff, then retains the inactive quote for audit.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class CancelReplacementIntent implements CancelReplacementIntentInterface
{
    private const MAX_COMMENT_LENGTH = 1000;

    private ExchangeRepositoryInterface $exchangeRepository;

    private ExchangeFactory $exchangeFactory;

    private ExchangeResource $exchangeResource;

    private ReplacementItemResource $replacementItemResource;

    private ReturnItemResource $returnItemResource;

    private DocumentLinkResource $documentLinkResource;

    private HistoryFactory $historyFactory;

    private HistoryResource $historyResource;

    private FinancialAggregateCalculator $aggregateCalculator;

    private IntentHasher $intentHasher;

    private PreparedQuoteLookup $preparedQuoteLookup;

    private NativeOrderResolver $nativeOrderResolver;

    private CartRepositoryInterface $quoteRepository;

    private StateTransitionGuardInterface $transitionGuard;

    private WorkflowCoordinator $workflowCoordinator;

    private VersionGuard $versionGuard;

    private ReturnCreditProjection $returnCreditProjection;

    private BalanceCalculatorInterface $balanceCalculator;

    private DecimalMath $moneyMath;

    private OrderMutexInterface $orderMutex;

    public function __construct(
        ExchangeRepositoryInterface $exchangeRepository,
        ExchangeFactory $exchangeFactory,
        ExchangeResource $exchangeResource,
        ReplacementItemResource $replacementItemResource,
        ReturnItemResource $returnItemResource,
        DocumentLinkResource $documentLinkResource,
        HistoryFactory $historyFactory,
        HistoryResource $historyResource,
        FinancialAggregateCalculator $aggregateCalculator,
        IntentHasher $intentHasher,
        PreparedQuoteLookup $preparedQuoteLookup,
        NativeOrderResolver $nativeOrderResolver,
        CartRepositoryInterface $quoteRepository,
        StateTransitionGuardInterface $transitionGuard,
        WorkflowCoordinator $workflowCoordinator,
        VersionGuard $versionGuard,
        ReturnCreditProjection $returnCreditProjection,
        BalanceCalculatorInterface $balanceCalculator,
        DecimalMath $moneyMath,
        OrderMutexInterface $orderMutex
    ) {
        $this->exchangeRepository = $exchangeRepository;
        $this->exchangeFactory = $exchangeFactory;
        $this->exchangeResource = $exchangeResource;
        $this->replacementItemResource = $replacementItemResource;
        $this->returnItemResource = $returnItemResource;
        $this->documentLinkResource = $documentLinkResource;
        $this->historyFactory = $historyFactory;
        $this->historyResource = $historyResource;
        $this->aggregateCalculator = $aggregateCalculator;
        $this->intentHasher = $intentHasher;
        $this->preparedQuoteLookup = $preparedQuoteLookup;
        $this->nativeOrderResolver = $nativeOrderResolver;
        $this->quoteRepository = $quoteRepository;
        $this->transitionGuard = $transitionGuard;
        $this->workflowCoordinator = $workflowCoordinator;
        $this->versionGuard = $versionGuard;
        $this->returnCreditProjection = $returnCreditProjection;
        $this->balanceCalculator = $balanceCalculator;
        $this->moneyMath = $moneyMath;
        $this->orderMutex = $orderMutex;
    }

    /**
     * @inheritdoc
     */
    public function execute(
        int $exchangeId,
        int $expectedVersion,
        int $actorId,
        ?string $comment = null
    ): ExchangeInterface {
        if ($exchangeId <= 0 || $expectedVersion <= 0 || $actorId <= 0) {
            throw new InvariantViolationException(
                __('A valid exchange, version, and admin actor are required.')
            );
        }
        $comment = $this->normalizeComment($comment);

        try {
            $initial = $this->exchangeRepository->getById($exchangeId);
            $originalOrderId = $initial->getOriginalOrderId();
            if ($originalOrderId <= 0) {
                throw new InvariantViolationException(
                    __('The exchange original order identity is invalid.')
                );
            }

            /** @var ExchangeInterface $exchange */
            $exchange = $this->orderMutex->execute(
                $originalOrderId,
                \Closure::fromCallable([$this, 'cancelLocked']),
                [
                    $exchangeId,
                    $originalOrderId,
                    $expectedVersion,
                    $actorId,
                    $comment,
                ]
            );

            return $exchange;
        } catch (\Throwable $exception) {
            if ($exception instanceof LocalizedException) {
                throw $exception;
            }
            throw new CouldNotSaveException(
                __('The exchange replacement intent could not be cancelled.'),
                $exception
            );
        }
    }

    /**
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    private function cancelLocked(
        int $exchangeId,
        int $originalOrderId,
        int $expectedVersion,
        int $actorId,
        ?string $comment
    ): ExchangeInterface {
        $row = $this->exchangeResource->getDataForUpdate($exchangeId);
        if ($row === null) {
            throw new NoSuchEntityException(
                __('No exchange case exists for ID "%1".', $exchangeId)
            );
        }
        if ((int)$row[ExchangeInterface::ORIGINAL_ORDER_ID] !== $originalOrderId) {
            throw new InvariantViolationException(
                __('The exchange original order changed while it was being locked.')
            );
        }

        $replacementRows = $this->replacementItemResource
            ->getRowsByExchangeIdForUpdate($exchangeId);
        $returnRows = $this->returnItemResource
            ->getRowsByExchangeIdForUpdate($exchangeId);
        $documentRows = $this->documentLinkResource
            ->getRowsByExchangeIdForUpdate($exchangeId);
        $exchange = $this->exchangeFactory->create();
        $exchange->setData($row);
        $fromStatus = $exchange->getReplacementStatus();
        $this->transitionGuard->executeReplacementIntentCancellation($fromStatus);
        $isReplay = $fromStatus === ReplacementStatus::CANCELLED;

        if (!$isReplay) {
            $this->workflowCoordinator->assertReplacementIntentCancellation(
                $exchange,
                $replacementRows
            );
            $nextVersion = $this->versionGuard->assertCurrentAndIncrement(
                $expectedVersion,
                (int)$row[ExchangeInterface::VERSION],
                'exchange case'
            );
        } else {
            $nextVersion = (int)$row[ExchangeInterface::VERSION];
        }

        $approvedAmount = $this->aggregateCalculator->getReplacementAmount(
            $replacementRows
        );
        $this->assertFrozenSnapshot(
            $exchange,
            $fromStatus,
            $approvedAmount
        );
        $intentExchange = $this->exchangeFactory->create();
        $intentExchange->setData($row)
            ->setReplacementAmount($approvedAmount)
            ->setFeeAmount('0.0000');
        $intentHash = $this->intentHasher->execute(
            $intentExchange,
            $replacementRows
        );
        $quote = $this->preparedQuoteLookup->find(
            $exchangeId,
            $intentHash
        );
        $quoteId = $quote === null ? null : (int)$quote->getId();
        if ($quote !== null && $quoteId <= 0) {
            throw new InvariantViolationException(
                __('The prepared replacement quote is not persisted.')
            );
        }
        if ($this->nativeOrderResolver->find(
            $exchangeId,
            $intentHash,
            $quoteId
        ) !== null) {
            throw new InvariantViolationException(
                __('A native replacement order already exists and cannot be cancelled as an intent.')
            );
        }
        $this->assertNoNativeHandoff(
            $exchange,
            $replacementRows,
            $documentRows
        );

        $projectedCredit = $this->getProjectedReturnCredit(
            $exchange,
            $returnRows
        );
        $balance = $this->balanceCalculator->execute(
            '0.0000',
            '0.0000',
            '0.0000',
            $projectedCredit
        );

        $this->deactivateQuote($quote);
        if ($isReplay) {
            $this->assertReplaySnapshot(
                $exchange,
                $approvedAmount,
                $balance
            );

            return $exchange;
        }

        $fromBalance = $exchange->getBalanceAmount();
        $fromFee = $exchange->getFeeAmount();
        $fromNative = $exchange->getNativeReplacementAmount();
        if ($fromStatus === ReplacementStatus::PENDING) {
            $exchange->setReplacementAmount('0.0000')
                ->setShippingAmount('0.0000');
        }
        $exchange->setReplacementStatus(ReplacementStatus::CANCELLED)
            ->setFeeAmount('0.0000')
            ->setNativeReplacementAmount('0.0000')
            ->setBaseNativeReplacementAmount('0.0000')
            ->setBalanceAmount($balance)
            ->setVersion($nextVersion);
        $this->exchangeResource->save($exchange);
        $this->recordHistory(
            $exchange,
            $fromStatus,
            $fromFee,
            $fromNative,
            $fromBalance,
            $balance,
            $quoteId,
            $actorId,
            $comment
        );

        return $exchange;
    }

    private function assertFrozenSnapshot(
        ExchangeInterface $exchange,
        string $fromStatus,
        string $approvedAmount
    ): void {
        $frozenMatches = $this->moneyMath->compare(
            $exchange->getReplacementAmount(),
            $approvedAmount
        ) === 0;
        $pendingMatches = $fromStatus === ReplacementStatus::PENDING
            && $this->moneyMath->compare(
                $exchange->getReplacementAmount(),
                '0.0000'
            ) === 0
            && $this->moneyMath->compare(
                $exchange->getShippingAmount(),
                '0.0000'
            ) === 0;
        $cancelledMatches = $fromStatus === ReplacementStatus::CANCELLED
            && ($frozenMatches || $this->moneyMath->compare(
                $exchange->getReplacementAmount(),
                '0.0000'
            ) === 0);
        if (!$frozenMatches && !$pendingMatches && !$cancelledMatches) {
            throw new InvariantViolationException(
                __('The replacement cancellation snapshot does not match its immutable item rows.')
            );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $replacementRows
     * @param array<int, array<string, mixed>> $documentRows
     */
    private function assertNoNativeHandoff(
        ExchangeInterface $exchange,
        array $replacementRows,
        array $documentRows
    ): void {
        foreach ($documentRows as $row) {
            if ((string)($row[DocumentLinkInterface::DOCUMENT_TYPE] ?? '')
                === DocumentType::ORDER
            ) {
                throw new InvariantViolationException(
                    __('A linked replacement order prevents replacement cancellation.')
                );
            }
        }
        foreach ($replacementRows as $row) {
            if (($row[ReplacementItemInterface::REPLACEMENT_ORDER_ITEM_ID] ?? null) !== null) {
                throw new InvariantViolationException(
                    __('A linked replacement order item prevents replacement cancellation.')
                );
            }
        }
        if ($this->moneyMath->compare(
            $exchange->getNativeReplacementAmount(),
            '0.0000'
        ) !== 0
            || $this->moneyMath->compare(
                $exchange->getBaseNativeReplacementAmount(),
                '0.0000'
            ) !== 0
        ) {
            throw new InvariantViolationException(
                __('Native replacement totals prevent replacement intent cancellation.')
            );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $returnRows
     */
    private function getProjectedReturnCredit(
        ExchangeInterface $exchange,
        array $returnRows
    ): string {
        if (!in_array(
            $exchange->getReturnStatus(),
            [ReturnStatus::ACCEPTED, ReturnStatus::PARTIALLY_ACCEPTED],
            true
        )) {
            return '0.0000';
        }

        return $this->returnCreditProjection->execute(
            $exchange->getNativeReturnCreditAmount(),
            $returnRows
        );
    }

    private function deactivateQuote(?Quote $quote): void
    {
        if ($quote === null || !(bool)$quote->getIsActive()) {
            return;
        }
        $quote->setIsActive(false);
        $this->quoteRepository->save($quote);
        $persisted = $this->quoteRepository->get((int)$quote->getId());
        if (!$persisted instanceof Quote
            || (int)$persisted->getId() !== (int)$quote->getId()
            || (bool)$persisted->getIsActive()
        ) {
            throw new InvariantViolationException(
                __('The prepared replacement quote could not be deactivated.')
            );
        }
    }

    private function assertReplaySnapshot(
        ExchangeInterface $exchange,
        string $approvedAmount,
        string $balance
    ): void {
        $replacementAmount = $exchange->getReplacementAmount();
        if (($this->moneyMath->compare($replacementAmount, '0.0000') !== 0
                && $this->moneyMath->compare(
                    $replacementAmount,
                    $approvedAmount
                ) !== 0)
            || $this->moneyMath->compare(
                $exchange->getFeeAmount(),
                '0.0000'
            ) !== 0
            || $this->moneyMath->compare(
                $exchange->getBalanceAmount(),
                $balance
            ) !== 0
        ) {
            throw new InvariantViolationException(
                __('The cancelled replacement snapshot is inconsistent with its refund-only projection.')
            );
        }
    }

    private function recordHistory(
        ExchangeInterface $exchange,
        string $fromStatus,
        string $fromFee,
        string $fromNative,
        string $fromBalance,
        string $balance,
        ?int $quoteId,
        int $actorId,
        ?string $comment
    ): void {
        $history = $this->historyFactory->create();
        $history->setExchangeId((int)$exchange->getEntityId())
            ->setAction('replacement_intent_cancelled')
            ->setStatusDimension(StateDimension::REPLACEMENT)
            ->setFromValue(
                sprintf(
                    'status=%s;fee=%s;native=%s;balance=%s',
                    $fromStatus,
                    $fromFee,
                    $fromNative,
                    $fromBalance
                )
            )->setToValue(
                sprintf(
                    'status=%s;approved=%s;shipping=%s;native=0.0000;balance=%s;quote=%s',
                    ReplacementStatus::CANCELLED,
                    $exchange->getReplacementAmount(),
                    $exchange->getShippingAmount(),
                    $balance,
                    $quoteId === null ? 'none' : (string)$quoteId
                )
            )->setActorType(ActorType::ADMIN)
            ->setActorId($actorId)
            ->setComment($comment);
        $this->historyResource->save($history);
    }

    private function normalizeComment(?string $comment): ?string
    {
        if ($comment === null) {
            return null;
        }
        $comment = trim($comment);
        if (mb_strlen($comment) > self::MAX_COMMENT_LENGTH) {
            throw new InvariantViolationException(
                __('An action comment cannot exceed 1000 characters.')
            );
        }

        return $comment === '' ? null : $comment;
    }
}
