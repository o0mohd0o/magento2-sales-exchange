<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\ReplacementOrder;

use Bonlineco\SalesExchange\Api\BalanceCalculatorInterface;
use Bonlineco\SalesExchange\Api\ConfigInterface;
use Bonlineco\SalesExchange\Api\CreateReplacementOrderInterface;
use Bonlineco\SalesExchange\Api\Data\DocumentLinkInterface;
use Bonlineco\SalesExchange\Api\Data\ExchangeInterface;
use Bonlineco\SalesExchange\Api\Data\ReplacementItemInterface;
use Bonlineco\SalesExchange\Api\DocumentType;
use Bonlineco\SalesExchange\Api\ExchangeRepositoryInterface;
use Bonlineco\SalesExchange\Api\History\ActorType;
use Bonlineco\SalesExchange\Api\Status\ExchangeStatus;
use Bonlineco\SalesExchange\Api\Status\ReplacementStatus;
use Bonlineco\SalesExchange\Api\Status\ReturnStatus;
use Bonlineco\SalesExchange\Api\Status\SettlementStatus;
use Bonlineco\SalesExchange\Api\Status\StateDimension;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Creditmemo\ReturnCreditProjection;
use Bonlineco\SalesExchange\Model\DocumentLink;
use Bonlineco\SalesExchange\Model\DocumentLinkFactory;
use Bonlineco\SalesExchange\Model\DocumentLinkWriter;
use Bonlineco\SalesExchange\Model\Exchange;
use Bonlineco\SalesExchange\Model\ExchangeFactory;
use Bonlineco\SalesExchange\Model\FinancialAggregateCalculator;
use Bonlineco\SalesExchange\Model\HistoryFactory;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Bonlineco\SalesExchange\Model\ReplacementItemFactory;
use Bonlineco\SalesExchange\Model\ResourceModel\DocumentLink as DocumentLinkResource;
use Bonlineco\SalesExchange\Model\ResourceModel\Exchange as ExchangeResource;
use Bonlineco\SalesExchange\Model\ResourceModel\History as HistoryResource;
use Bonlineco\SalesExchange\Model\ResourceModel\ReplacementItem as ReplacementItemResource;
use Bonlineco\SalesExchange\Model\ResourceModel\ReturnItem as ReturnItemResource;
use Bonlineco\SalesExchange\Model\VersionGuard;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\OrderMutexInterface;
use Psr\Log\LoggerInterface;

/**
 * Prepare, place, and idempotently reconcile one native replacement order.
 *
 * The first original-order transaction durably prepares an inactive quote.
 * The second places and reconciles the native order atomically. Existing
 * marked orders are reconciled immediately while both order rows are locked.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
 * @SuppressWarnings(PHPMD.TooManyFields)
 */
class CreateReplacementOrder implements CreateReplacementOrderInterface
{
    private ConfigInterface $config;

    private ExchangeRepositoryInterface $exchangeRepository;

    private ExchangeResource $exchangeResource;

    private ExchangeFactory $exchangeFactory;

    private ReplacementItemResource $replacementItemResource;

    private ReplacementItemFactory $replacementItemFactory;

    private ReturnItemResource $returnItemResource;

    private DocumentLinkResource $documentLinkResource;

    private DocumentLinkFactory $documentLinkFactory;

    private DocumentLinkWriter $documentLinkWriter;

    private HistoryFactory $historyFactory;

    private HistoryResource $historyResource;

    private OrderMutexInterface $orderMutex;

    private OrderRepositoryInterface $orderRepository;

    private QuotePreparer $quotePreparer;

    private PreparedQuoteLookup $preparedQuoteLookup;

    private IntentHasher $intentHasher;

    private NativeOrderResolver $nativeOrderResolver;

    private NativeOrderPlacer $nativeOrderPlacer;

    private NativeOrderValidator $nativeOrderValidator;

    private FinancialAggregateCalculator $aggregateCalculator;

    private ReturnCreditProjection $returnCreditProjection;

    private BalanceCalculatorInterface $balanceCalculator;

    private VersionGuard $versionGuard;

    private DecimalMath $moneyMath;

    private ManagerInterface $eventManager;

    private LoggerInterface $logger;

    public function __construct(
        ConfigInterface $config,
        ExchangeRepositoryInterface $exchangeRepository,
        ExchangeResource $exchangeResource,
        ExchangeFactory $exchangeFactory,
        ReplacementItemResource $replacementItemResource,
        ReplacementItemFactory $replacementItemFactory,
        ReturnItemResource $returnItemResource,
        DocumentLinkResource $documentLinkResource,
        DocumentLinkFactory $documentLinkFactory,
        DocumentLinkWriter $documentLinkWriter,
        HistoryFactory $historyFactory,
        HistoryResource $historyResource,
        OrderMutexInterface $orderMutex,
        OrderRepositoryInterface $orderRepository,
        QuotePreparer $quotePreparer,
        PreparedQuoteLookup $preparedQuoteLookup,
        IntentHasher $intentHasher,
        NativeOrderResolver $nativeOrderResolver,
        NativeOrderPlacer $nativeOrderPlacer,
        NativeOrderValidator $nativeOrderValidator,
        FinancialAggregateCalculator $aggregateCalculator,
        ReturnCreditProjection $returnCreditProjection,
        BalanceCalculatorInterface $balanceCalculator,
        VersionGuard $versionGuard,
        DecimalMath $moneyMath,
        ManagerInterface $eventManager,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->exchangeRepository = $exchangeRepository;
        $this->exchangeResource = $exchangeResource;
        $this->exchangeFactory = $exchangeFactory;
        $this->replacementItemResource = $replacementItemResource;
        $this->replacementItemFactory = $replacementItemFactory;
        $this->returnItemResource = $returnItemResource;
        $this->documentLinkResource = $documentLinkResource;
        $this->documentLinkFactory = $documentLinkFactory;
        $this->documentLinkWriter = $documentLinkWriter;
        $this->historyFactory = $historyFactory;
        $this->historyResource = $historyResource;
        $this->orderMutex = $orderMutex;
        $this->orderRepository = $orderRepository;
        $this->quotePreparer = $quotePreparer;
        $this->preparedQuoteLookup = $preparedQuoteLookup;
        $this->intentHasher = $intentHasher;
        $this->nativeOrderResolver = $nativeOrderResolver;
        $this->nativeOrderPlacer = $nativeOrderPlacer;
        $this->nativeOrderValidator = $nativeOrderValidator;
        $this->aggregateCalculator = $aggregateCalculator;
        $this->returnCreditProjection = $returnCreditProjection;
        $this->balanceCalculator = $balanceCalculator;
        $this->versionGuard = $versionGuard;
        $this->moneyMath = $moneyMath;
        $this->eventManager = $eventManager;
        $this->logger = $logger;
    }

    public function execute(
        int $exchangeId,
        int $expectedVersion,
        int $actorId,
        ?string $comment = null
    ): DocumentLinkInterface {
        if ($exchangeId <= 0 || $expectedVersion <= 0 || $actorId <= 0) {
            throw new InvariantViolationException(
                __('A valid exchange, version, and admin actor are required.')
            );
        }
        $comment = $this->normalizeComment($comment);
        $operationKey = $this->operationKey($exchangeId);
        $initial = $this->exchangeRepository->getById($exchangeId);
        $originalOrderId = $initial->getOriginalOrderId();

        try {
            /** @var array{
             *     intent_hash: string,
             *     version: int,
             *     quote_id: int|null,
             *     result: array{
             *         link: DocumentLinkInterface,
             *         order: OrderInterface,
             *         changed: bool,
             *         item_ids: array<int, int>
             *     }|null
             * } $prepared */
            $prepared = $this->orderMutex->execute(
                $originalOrderId,
                \Closure::fromCallable([$this, 'prepareLocked']),
                [
                    $exchangeId,
                    $originalOrderId,
                    $expectedVersion,
                    $actorId,
                    $comment,
                    $operationKey,
                ]
            );
            if ($prepared['result'] === null) {
                /** @var array{link: DocumentLinkInterface, order: OrderInterface, changed: bool, item_ids: array<int, int>} $result */
                $result = $this->orderMutex->execute(
                    $originalOrderId,
                    \Closure::fromCallable(
                        [$this, 'placeAndReconcileLocked']
                    ),
                    [
                        $exchangeId,
                        $originalOrderId,
                        $prepared['version'],
                        $prepared['intent_hash'],
                        $prepared['quote_id'],
                        $actorId,
                        $comment,
                        $operationKey,
                    ]
                );
            } else {
                $result = $prepared['result'];
            }
        } catch (\Throwable $exception) {
            if ($exception instanceof LocalizedException) {
                throw $exception;
            }
            $this->logger->critical(
                'Unexpected exchange replacement order failure.',
                [
                    'exception' => $exception,
                    'exchange_id' => $exchangeId,
                ]
            );
            $cause = $exception instanceof \Exception
                ? $exception
                : new \RuntimeException(
                    $exception->getMessage(),
                    (int)$exception->getCode(),
                    $exception
                );
            throw new CouldNotSaveException(
                __('The exchange replacement order could not be created.'),
                $cause
            );
        }

        if ($result['changed']) {
            try {
                $this->eventManager->dispatch(
                    'bonlineco_sales_exchange_replacement_order_created',
                    [
                        'exchange_id' => $exchangeId,
                        'document_link' => $result['link'],
                        'order' => $result['order'],
                        'replacement_item_order_item_ids' => $result['item_ids'],
                    ]
                );
            } catch (\Throwable $exception) {
                // Native and module records are already durable.
                $this->logger->critical($exception);
            }
        }

        return $result['link'];
    }

    /**
     * @return array{
     *     intent_hash: string,
     *     version: int,
     *     quote_id: int|null,
     *     result: array{
     *         link: DocumentLinkInterface,
     *         order: OrderInterface,
     *         changed: bool,
     *         item_ids: array<int, int>
     *     }|null
     * }
     */
    private function prepareLocked(
        int $exchangeId,
        int $originalOrderId,
        int $expectedVersion,
        int $actorId,
        ?string $comment,
        string $operationKey
    ): array {
        $state = $this->loadStaticState($exchangeId, $originalOrderId);
        $linkRow = $this->documentLinkResource->getByOperationKeyForUpdate(
            $operationKey
        );
        $quote = $this->preparedQuoteLookup->find(
            $exchangeId,
            $state['intent_hash']
        );
        $quoteId = $quote === null ? null : (int)$quote->getId();
        if ($linkRow !== null) {
            $linkedOrderId = (int)$linkRow[
                DocumentLinkInterface::DOCUMENT_ID
            ];

            return $this->withReplacementOrderLock(
                $originalOrderId,
                $linkedOrderId,
                \Closure::fromCallable([$this, 'recoverCommittedLocked']),
                [
                    $exchangeId,
                    $originalOrderId,
                    $linkedOrderId,
                    $quoteId,
                    $operationKey,
                    $state,
                    $linkRow,
                    $actorId,
                    $comment,
                ]
            );
        }
        $nativeOrder = $this->nativeOrderResolver->find(
            $exchangeId,
            $state['intent_hash'],
            $quoteId
        );
        if ($nativeOrder !== null) {
            $nativeOrderId = (int)$nativeOrder->getEntityId();

            return $this->withReplacementOrderLock(
                $originalOrderId,
                $nativeOrderId,
                \Closure::fromCallable([$this, 'recoverCommittedLocked']),
                [
                    $exchangeId,
                    $originalOrderId,
                    $nativeOrderId,
                    $quoteId,
                    $operationKey,
                    $state,
                    null,
                    $actorId,
                    $comment,
                ]
            );
        }
        $this->assertWorkflow($state['exchange']);
        if ($state['exchange']->getReplacementStatus()
            === ReplacementStatus::ORDERED
        ) {
            throw new InvariantViolationException(
                __('The ordered replacement has no marked native Magento order.')
            );
        }

        $fromStatus = $state['exchange']->getReplacementStatus();
        $isExactReadyReplay = $fromStatus === ReplacementStatus::READY
            && $quote !== null;
        $nextVersion = $isExactReadyReplay
            ? (int)$state['row'][ExchangeInterface::VERSION]
            : $this->versionGuard->assertCurrentAndIncrement(
                $expectedVersion,
                (int)$state['row'][ExchangeInterface::VERSION],
                'exchange case'
            );
        $originalOrder = $this->orderRepository->get($originalOrderId);
        if ($fromStatus === ReplacementStatus::PENDING) {
            $state['exchange']->setReplacementStatus(ReplacementStatus::READY);
        }
        $quote = $this->quotePreparer->execute(
            $originalOrder,
            $state['exchange'],
            $state['replacement_rows'],
            $state['intent_hash']
        );
        if ($fromStatus === ReplacementStatus::PENDING) {
            $fromBalance = $state['exchange']->getBalanceAmount();
            $returnRows = $this->returnItemResource
                ->getRowsByExchangeIdForUpdate($exchangeId);
            $projectedCredit = $this->returnCreditProjection->execute(
                $state['exchange']->getNativeReturnCreditAmount(),
                $returnRows
            );
            $balance = $this->balanceCalculator->execute(
                $this->moneyMath->add(
                    $state['approved_amount'],
                    $state['exchange']->getShippingAmount()
                ),
                '0.0000',
                $state['exchange']->getFeeAmount(),
                $projectedCredit
            );
            $state['exchange']->setReplacementAmount(
                $state['approved_amount']
            )->setReplacementStatus(
                ReplacementStatus::READY
            )->setBalanceAmount(
                $balance
            )->setVersion(
                $nextVersion
            );
            $this->exchangeResource->save($state['exchange']);
            $this->recordReadyHistory(
                $exchangeId,
                $actorId,
                $state['approved_amount'],
                $fromBalance,
                $balance,
                $comment
            );
        }

        return [
            'intent_hash' => $state['intent_hash'],
            'version' => $fromStatus === ReplacementStatus::PENDING
                ? $nextVersion
                : (int)$state['row'][ExchangeInterface::VERSION],
            'quote_id' => (int)$quote->getId(),
            'result' => null,
        ];
    }

    /**
     * Place and complete the module handoff before releasing the original row.
     *
     * @return array{link: DocumentLinkInterface, order: OrderInterface, changed: bool, item_ids: array<int, int>}
     */
    private function placeAndReconcileLocked(
        int $exchangeId,
        int $originalOrderId,
        int $expectedVersion,
        string $expectedIntentHash,
        ?int $quoteId,
        int $actorId,
        ?string $comment,
        string $operationKey
    ): array {
        $nativeOrderId = $this->placeLocked(
            $exchangeId,
            $originalOrderId,
            $expectedVersion,
            $expectedIntentHash,
            $quoteId,
            $operationKey
        );

        return $this->reconcileLocked(
            $exchangeId,
            $originalOrderId,
            $nativeOrderId,
            $expectedIntentHash,
            $actorId,
            $comment,
            $operationKey
        );
    }

    private function placeLocked(
        int $exchangeId,
        int $originalOrderId,
        int $expectedVersion,
        string $expectedIntentHash,
        ?int $quoteId,
        string $operationKey
    ): int {
        $state = $this->loadState($exchangeId, $originalOrderId);
        $this->assertIntent($expectedIntentHash, $state['intent_hash']);
        $linkRow = $this->documentLinkResource->getByOperationKeyForUpdate(
            $operationKey
        );
        $quote = $this->preparedQuoteLookup->find(
            $exchangeId,
            $state['intent_hash']
        );
        if ($quote === null
            || $quoteId === null
            || (int)$quote->getId() !== $quoteId
        ) {
            throw new InvariantViolationException(
                __('The durable prepared replacement quote is missing or changed.')
            );
        }
        $nativeOrder = $this->nativeOrderResolver->find(
            $exchangeId,
            $state['intent_hash'],
            $quoteId
        );
        if ($nativeOrder !== null) {
            $nativeOrderId = (int)$nativeOrder->getEntityId();

            return (int)$this->withReplacementOrderLock(
                $originalOrderId,
                $nativeOrderId,
                \Closure::fromCallable(
                    [$this, 'validateCommittedOrderLocked']
                ),
                [
                    $exchangeId,
                    $originalOrderId,
                    $nativeOrderId,
                    $quoteId,
                    $state,
                ]
            );
        }
        if ($linkRow !== null) {
            throw new InvariantViolationException(
                __('A replacement order link exists without its native order.')
            );
        }
        if ($state['exchange']->getReplacementStatus()
                !== ReplacementStatus::READY
        ) {
            throw new InvariantViolationException(
                __('Only a ready replacement quote can be placed.')
            );
        }
        $this->versionGuard->assertCurrentAndIncrement(
            $expectedVersion,
            (int)$state['row'][ExchangeInterface::VERSION],
            'exchange case'
        );
        $originalOrder = $this->orderRepository->get($originalOrderId);

        // NativeOrderPlacer runs inside this original-order mutex and marks
        // the shared adapter rollback-only on every placement failure. Do not
        // query for or lock the still-visible uncommitted order here: opening
        // another nested transaction would mask the real failure with
        // "Rolled back transaction has not been completed correctly."
        // The outer mutex must perform the physical rollback first; a later
        // request can recover any genuinely committed idempotent result.
        $placedOrderId = $this->nativeOrderPlacer->execute(
            $quote,
            $originalOrder,
            $state['exchange'],
            $state['replacement_rows'],
            $state['intent_hash']
        );

        return (int)$this->withReplacementOrderLock(
            $originalOrderId,
            $placedOrderId,
            \Closure::fromCallable(
                [$this, 'validateCommittedOrderLocked']
            ),
            [
                $exchangeId,
                $originalOrderId,
                $placedOrderId,
                $quoteId,
                $state,
            ]
        );
    }

    /**
     * @return array{link: DocumentLinkInterface, order: OrderInterface, changed: bool, item_ids: array<int, int>}
     */
    private function reconcileLocked(
        int $exchangeId,
        int $originalOrderId,
        int $nativeOrderId,
        string $expectedIntentHash,
        int $actorId,
        ?string $comment,
        string $operationKey
    ): array {
        /** @var array{link: DocumentLinkInterface, order: OrderInterface, changed: bool, item_ids: array<int, int>} */
        return $this->withReplacementOrderLock(
            $originalOrderId,
            $nativeOrderId,
            \Closure::fromCallable([$this, 'reconcileReplacementLocked']),
            [
                $exchangeId,
                $originalOrderId,
                $nativeOrderId,
                $expectedIntentHash,
                $actorId,
                $comment,
                $operationKey,
            ]
        );
    }

    /**
     * @return array{link: DocumentLinkInterface, order: OrderInterface, changed: bool, item_ids: array<int, int>}
     */
    private function reconcileReplacementLocked(
        int $exchangeId,
        int $originalOrderId,
        int $nativeOrderId,
        string $expectedIntentHash,
        int $actorId,
        ?string $comment,
        string $operationKey
    ): array {
        $state = $this->loadStaticState($exchangeId, $originalOrderId);
        $this->assertIntent($expectedIntentHash, $state['intent_hash']);
        $linkRow = $this->documentLinkResource->getByOperationKeyForUpdate(
            $operationKey
        );
        $quote = $this->preparedQuoteLookup->find(
            $exchangeId,
            $state['intent_hash']
        );
        $nativeOrder = $this->nativeOrderResolver->find(
            $exchangeId,
            $state['intent_hash'],
            $quote === null ? null : (int)$quote->getId()
        );
        if ($nativeOrder === null
            || (int)$nativeOrder->getEntityId() !== $nativeOrderId
        ) {
            throw new InvariantViolationException(
                __('The native replacement order changed before reconciliation.')
            );
        }
        $snapshot = $this->nativeOrderValidator->snapshot(
            $nativeOrder,
            $this->orderRepository->get($originalOrderId),
            $state['exchange'],
            $state['replacement_rows'],
            $state['intent_hash'],
            $quote === null ? null : (int)$quote->getId()
        );

        $balance = $this->calculateOrderedBalance(
            $state['exchange'],
            $snapshot['amount'],
            $this->returnItemResource->getRowsByExchangeIdForUpdate($exchangeId)
        );
        $exchangeChanged = $this->assertOrDetectExchangeReconciliation(
            $state['exchange'],
            $snapshot['amount'],
            $snapshot['base_amount'],
            $balance
        );
        $link = $linkRow === null
            ? $this->createLink(
                $exchangeId,
                $operationKey,
                $nativeOrder,
                $snapshot
            )
            : $this->replayLink(
                $linkRow,
                $exchangeId,
                $operationKey,
                $nativeOrder,
                $snapshot
            );
        $changed = $linkRow === null;
        foreach ($state['replacement_rows'] as $row) {
            $replacementItemId = (int)$row[
                ReplacementItemInterface::ENTITY_ID
            ];
            $expectedOrderItemId = $snapshot['item_ids'][$replacementItemId]
                ?? null;
            $persistedOrderItemId = $row[
                ReplacementItemInterface::REPLACEMENT_ORDER_ITEM_ID
            ] ?? null;
            if ($expectedOrderItemId === null) {
                throw new InvariantViolationException(
                    __('A replacement row has no mapped native order item.')
                );
            }
            if ($persistedOrderItemId !== null
                && (int)$persistedOrderItemId !== $expectedOrderItemId
            ) {
                throw new InvariantViolationException(
                    __('A replacement row is linked to a different native order item.')
                );
            }
            if ($persistedOrderItemId !== null) {
                continue;
            }
            $item = $this->replacementItemFactory->create();
            $item->setData($row);
            $item->setReplacementOrderItemId($expectedOrderItemId)
                ->setVersion(
                    $this->versionGuard->assertCurrentAndIncrement(
                        (int)$row[ReplacementItemInterface::VERSION],
                        (int)$row[ReplacementItemInterface::VERSION],
                        'replacement item'
                    )
                );
            $this->replacementItemResource->save($item);
            $changed = true;
        }

        if ($exchangeChanged) {
            $nextVersion = $this->versionGuard->assertCurrentAndIncrement(
                (int)$state['row'][ExchangeInterface::VERSION],
                (int)$state['row'][ExchangeInterface::VERSION],
                'exchange case'
            );
            $fromStatus = $state['exchange']->getReplacementStatus();
            $fromNative = $state['exchange']->getNativeReplacementAmount();
            $fromBalance = $state['exchange']->getBalanceAmount();
            $state['exchange']->setReplacementAmount(
                $state['approved_amount']
            )->setNativeReplacementAmount(
                $snapshot['amount']
            )->setBaseNativeReplacementAmount(
                $snapshot['base_amount']
            )->setBalanceAmount(
                $balance
            )->setReplacementStatus(
                ReplacementStatus::ORDERED
            )->setVersion(
                $nextVersion
            );
            $this->exchangeResource->save($state['exchange']);
            $this->recordOrderHistory(
                $exchangeId,
                $actorId,
                $nativeOrder,
                $fromStatus,
                $fromNative,
                $snapshot['amount'],
                $fromBalance,
                $balance,
                $snapshot['expected_amount'],
                $comment
            );
            $changed = true;
        }

        return [
            'link' => $link,
            'order' => $nativeOrder,
            'changed' => $changed,
            'item_ids' => $snapshot['item_ids'],
        ];
    }

    /**
     * Re-resolve and validate a committed order while its native row is locked.
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed>|null $linkRow
     * @return array{
     *     intent_hash: string,
     *     version: int,
     *     quote_id: int|null,
     *     result: array{
     *         link: DocumentLinkInterface,
     *         order: OrderInterface,
     *         changed: bool,
     *         item_ids: array<int, int>
     *     }
     * }
     */
    private function recoverCommittedLocked(
        int $exchangeId,
        int $originalOrderId,
        int $nativeOrderId,
        ?int $quoteId,
        string $operationKey,
        array $state,
        ?array $linkRow,
        int $actorId,
        ?string $comment
    ): array {
        $committed = $this->snapshotCommittedOrderLocked(
            $exchangeId,
            $originalOrderId,
            $nativeOrderId,
            $quoteId,
            $state
        );
        if ($linkRow !== null) {
            $link = $this->replayLink(
                $linkRow,
                $exchangeId,
                $operationKey,
                $committed['order'],
                $committed['snapshot']
            );
            $this->assertStaticReconciliation(
                $state,
                $committed['snapshot']
            );
            $result = [
                'link' => $link,
                'order' => $committed['order'],
                'changed' => false,
                'item_ids' => $committed['snapshot']['item_ids'],
            ];
        } else {
            $result = $this->reconcileReplacementLocked(
                $exchangeId,
                $originalOrderId,
                $nativeOrderId,
                $state['intent_hash'],
                $actorId,
                $comment,
                $operationKey
            );
        }

        return [
            'intent_hash' => $state['intent_hash'],
            'version' => (int)$state['row'][ExchangeInterface::VERSION],
            'quote_id' => $quoteId,
            'result' => $result,
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function validateCommittedOrderLocked(
        int $exchangeId,
        int $originalOrderId,
        int $nativeOrderId,
        ?int $quoteId,
        array $state
    ): int {
        $this->snapshotCommittedOrderLocked(
            $exchangeId,
            $originalOrderId,
            $nativeOrderId,
            $quoteId,
            $state
        );

        return $nativeOrderId;
    }

    /**
     * @param array<string, mixed> $state
     * @return array{
     *     order: OrderInterface,
     *     snapshot: array{
     *         amount: string,
     *         base_amount: string,
     *         expected_amount: string,
     *         item_quantities_json: string,
     *         snapshot_hash: string,
     *         item_ids: array<int, int>
     *     }
     * }
     */
    private function snapshotCommittedOrderLocked(
        int $exchangeId,
        int $originalOrderId,
        int $nativeOrderId,
        ?int $quoteId,
        array $state
    ): array {
        $nativeOrder = $this->nativeOrderResolver->find(
            $exchangeId,
            $state['intent_hash'],
            $quoteId
        );
        if ($nativeOrder === null
            || (int)$nativeOrder->getEntityId() !== $nativeOrderId
        ) {
            throw new InvariantViolationException(
                __('The native replacement order changed before its row could be locked.')
            );
        }
        $snapshot = $this->nativeOrderValidator->snapshot(
            $nativeOrder,
            $this->orderRepository->get($originalOrderId),
            $state['exchange'],
            $state['replacement_rows'],
            $state['intent_hash'],
            $quoteId
        );

        return ['order' => $nativeOrder, 'snapshot' => $snapshot];
    }

    /**
     * Acquire native locks only after the original-order mutex is held.
     *
     * @param array<int, mixed> $args
     * @return mixed
     */
    private function withReplacementOrderLock(
        int $originalOrderId,
        int $replacementOrderId,
        callable $callback,
        array $args
    ) {
        if ($originalOrderId <= 0
            || $replacementOrderId <= 0
            || $replacementOrderId === $originalOrderId
        ) {
            throw new InvariantViolationException(
                __('The replacement order lock identity is invalid.')
            );
        }

        return $this->orderMutex->execute(
            $replacementOrderId,
            $callback,
            $args
        );
    }

    /**
     * @return array{
     *     row: array<string, mixed>,
     *     exchange: Exchange,
     *     replacement_rows: array<int, array<string, mixed>>,
     *     approved_amount: string,
     *     intent_hash: string
     * }
     */
    private function loadState(int $exchangeId, int $originalOrderId): array
    {
        $state = $this->loadStaticState($exchangeId, $originalOrderId);
        $this->assertWorkflow($state['exchange']);

        return $state;
    }

    /**
     * Load only immutable intent data. This path intentionally ignores current
     * workflow/config state so a completed operation can be replayed safely.
     *
     * @return array{
     *     row: array<string, mixed>,
     *     exchange: Exchange,
     *     replacement_rows: array<int, array<string, mixed>>,
     *     approved_amount: string,
     *     intent_hash: string
     * }
     */
    private function loadStaticState(
        int $exchangeId,
        int $originalOrderId
    ): array
    {
        $row = $this->exchangeResource->getDataForUpdate($exchangeId);
        if ($row === null) {
            throw new NoSuchEntityException(
                __('No exchange case exists for ID "%1".', $exchangeId)
            );
        }
        if ((int)$row[ExchangeInterface::ORIGINAL_ORDER_ID]
            !== $originalOrderId
        ) {
            throw new InvariantViolationException(
                __('The exchange original order changed while it was being locked.')
            );
        }
        $replacementRows = $this->replacementItemResource
            ->getRowsByExchangeIdForUpdate($exchangeId);
        $exchange = $this->exchangeFactory->create();
        $exchange->setData($row);
        $approved = $this->aggregateCalculator->getReplacementAmount(
            $replacementRows
        );
        if ($exchange->getReplacementStatus() === ReplacementStatus::PENDING) {
            if ($this->moneyMath->compare(
                $exchange->getReplacementAmount(),
                '0'
            ) !== 0) {
                throw new InvariantViolationException(
                    __('A pending replacement cannot already retain a frozen amount.')
                );
            }
            $exchange->setReplacementAmount($approved);
        } elseif ($this->moneyMath->compare(
            $exchange->getReplacementAmount(),
            $approved
        ) !== 0) {
            throw new InvariantViolationException(
                __('The frozen replacement amount no longer matches its item rows.')
            );
        }
        $intentHash = $this->intentHasher->execute(
            $exchange,
            $replacementRows
        );

        return [
            'row' => $row,
            'exchange' => $exchange,
            'replacement_rows' => $replacementRows,
            'approved_amount' => $approved,
            'intent_hash' => $intentHash,
        ];
    }

    /**
     * @param array{
     *     row: array<string, mixed>,
     *     exchange: Exchange,
     *     replacement_rows: array<int, array<string, mixed>>,
     *     approved_amount: string,
     *     intent_hash: string
     * } $state
     * @param array{
     *     amount: string,
     *     base_amount: string,
     *     expected_amount: string,
     *     item_quantities_json: string,
     *     snapshot_hash: string,
     *     item_ids: array<int, int>
     * } $snapshot
     */
    private function assertStaticReconciliation(
        array $state,
        array $snapshot
    ): void {
        foreach ($state['replacement_rows'] as $row) {
            $replacementItemId = (int)$row[
                ReplacementItemInterface::ENTITY_ID
            ];
            if (!isset($snapshot['item_ids'][$replacementItemId])
                || (int)($row[
                    ReplacementItemInterface::REPLACEMENT_ORDER_ITEM_ID
                ] ?? 0) !== $snapshot['item_ids'][$replacementItemId]
            ) {
                throw new InvariantViolationException(
                    __('The replayed replacement item handoff is incomplete.')
                );
            }
        }
        if ($this->moneyMath->compare(
            $state['exchange']->getNativeReplacementAmount(),
            $snapshot['amount']
        ) !== 0
            || $this->moneyMath->compare(
                $state['exchange']->getBaseNativeReplacementAmount(),
                $snapshot['base_amount']
            ) !== 0
        ) {
            throw new InvariantViolationException(
                __('The replayed replacement totals conflict with their native order.')
            );
        }
    }

    private function assertWorkflow(ExchangeInterface $exchange): void
    {
        if (!$this->config->isEnabled($exchange->getStoreId())) {
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
            || !in_array(
                $exchange->getReplacementStatus(),
                [
                    ReplacementStatus::PENDING,
                    ReplacementStatus::READY,
                    ReplacementStatus::ORDERED,
                ],
                true
            )
        ) {
            throw new InvariantViolationException(
                __(
                    'A replacement order requires an in-progress exchange, '
                    . 'an accepted return, pending settlement, and a pending, '
                    . 'ready, or ordered replacement.'
                )
            );
        }
        if ($exchange->getReplacementStatus() !== ReplacementStatus::ORDERED
            && ($this->moneyMath->compare(
                $exchange->getNativeReplacementAmount(),
                '0'
            ) !== 0
                || $this->moneyMath->compare(
                    $exchange->getBaseNativeReplacementAmount(),
                    '0'
                ) !== 0)
        ) {
            throw new InvariantViolationException(
                __('An unplaced replacement cannot retain native order totals.')
            );
        }
    }

    /**
     * @param array{
     *     amount: string,
     *     base_amount: string,
     *     expected_amount: string,
     *     item_quantities_json: string,
     *     snapshot_hash: string,
     *     item_ids: array<int, int>
     * } $snapshot
     */
    private function createLink(
        int $exchangeId,
        string $operationKey,
        OrderInterface $order,
        array $snapshot
    ): DocumentLinkInterface {
        $link = $this->documentLinkFactory->create();
        $link->setExchangeId($exchangeId)
            ->setDocumentType(DocumentType::ORDER)
            ->setDocumentId((int)$order->getEntityId())
            ->setIncrementId((string)$order->getIncrementId())
            ->setOperationKey($operationKey)
            ->setItemQuantitiesJson($snapshot['item_quantities_json'])
            ->setSnapshotHash($snapshot['snapshot_hash'])
            ->setAmount($snapshot['amount'])
            ->setExpectedAmount($snapshot['expected_amount'])
            ->setBaseAmount($snapshot['base_amount'])
            ->setCurrencyCode((string)$order->getOrderCurrencyCode())
            ->setBaseCurrencyCode((string)$order->getBaseCurrencyCode())
            ->setDocumentStatus((string)$order->getStatus());

        return $this->documentLinkWriter->append($link);
    }

    /**
     * @param array<string, mixed> $row
     * @param array{
     *     amount: string,
     *     base_amount: string,
     *     expected_amount: string,
     *     item_quantities_json: string,
     *     snapshot_hash: string,
     *     item_ids: array<int, int>
     * } $snapshot
     */
    private function replayLink(
        array $row,
        int $exchangeId,
        string $operationKey,
        OrderInterface $order,
        array $snapshot
    ): DocumentLinkInterface {
        $matches = (int)$row[DocumentLinkInterface::EXCHANGE_ID]
                === $exchangeId
            && (string)$row[DocumentLinkInterface::DOCUMENT_TYPE]
                === DocumentType::ORDER
            && (int)$row[DocumentLinkInterface::DOCUMENT_ID]
                === (int)$order->getEntityId()
            && (string)$row[DocumentLinkInterface::INCREMENT_ID]
                === (string)$order->getIncrementId()
            && (string)$row[DocumentLinkInterface::OPERATION_KEY]
                === $operationKey
            && (string)$row[DocumentLinkInterface::ITEM_QUANTITIES_JSON]
                === $snapshot['item_quantities_json']
            && is_string($row[DocumentLinkInterface::SNAPSHOT_HASH] ?? null)
            && hash_equals(
                $snapshot['snapshot_hash'],
                (string)$row[DocumentLinkInterface::SNAPSHOT_HASH]
            )
            && (string)$row[DocumentLinkInterface::CURRENCY_CODE]
                === (string)$order->getOrderCurrencyCode()
            && (string)$row[DocumentLinkInterface::BASE_CURRENCY_CODE]
                === (string)$order->getBaseCurrencyCode()
            && $this->moneyMath->compare(
                (string)$row[DocumentLinkInterface::AMOUNT],
                $snapshot['amount']
            ) === 0
            && $this->moneyMath->compare(
                (string)$row[DocumentLinkInterface::EXPECTED_AMOUNT],
                $snapshot['expected_amount']
            ) === 0
            && $this->moneyMath->compare(
                (string)$row[DocumentLinkInterface::BASE_AMOUNT],
                $snapshot['base_amount']
            ) === 0;
        if (!$matches) {
            throw new InvariantViolationException(
                __('The replacement order operation key is linked to a different native snapshot.')
            );
        }
        /** @var DocumentLink $link */
        $link = $this->documentLinkFactory->create();
        $link->setData($row);

        return $link;
    }

    /**
     * @param array<int, array<string, mixed>> $returnRows
     */
    private function calculateOrderedBalance(
        ExchangeInterface $exchange,
        string $nativeAmount,
        array $returnRows
    ): string {
        $projectedCredit = $this->returnCreditProjection->execute(
            $exchange->getNativeReturnCreditAmount(),
            $returnRows
        );

        return $this->balanceCalculator->execute(
            $nativeAmount,
            '0.0000',
            $exchange->getFeeAmount(),
            $projectedCredit
        );
    }

    private function assertOrDetectExchangeReconciliation(
        ExchangeInterface $exchange,
        string $nativeAmount,
        string $baseNativeAmount,
        string $balance
    ): bool {
        $status = $exchange->getReplacementStatus();
        $returnAccepted = in_array(
            $exchange->getReturnStatus(),
            [ReturnStatus::ACCEPTED, ReturnStatus::PARTIALLY_ACCEPTED],
            true
        );
        if (!$returnAccepted) {
            throw new InvariantViolationException(
                __('A committed replacement order requires an accepted return.')
            );
        }
        if ($status === ReplacementStatus::READY) {
            if ($exchange->getExchangeStatus() !== ExchangeStatus::IN_PROGRESS
                || $exchange->getSettlementStatus()
                    !== SettlementStatus::PENDING
                || $this->moneyMath->compare(
                    $exchange->getNativeReplacementAmount(),
                    '0'
                ) !== 0
                || $this->moneyMath->compare(
                    $exchange->getBaseNativeReplacementAmount(),
                    '0'
                ) !== 0
            ) {
                throw new InvariantViolationException(
                    __('The ready replacement state contradicts its committed native order.')
                );
            }

            return true;
        }
        if (!in_array(
            $status,
            [
                ReplacementStatus::ORDERED,
                ReplacementStatus::SHIPPED,
                ReplacementStatus::DELIVERED,
            ],
            true
        )
            || !in_array(
                $exchange->getExchangeStatus(),
                [ExchangeStatus::IN_PROGRESS, ExchangeStatus::COMPLETED],
                true
            )
            || in_array(
                $exchange->getSettlementStatus(),
                [SettlementStatus::FAILED, SettlementStatus::CANCELLED],
                true
            )
        ) {
            throw new InvariantViolationException(
                __('The committed replacement order conflicts with the exchange workflow state.')
            );
        }
        if ($this->moneyMath->compare(
            $exchange->getNativeReplacementAmount(),
            $nativeAmount
        ) !== 0
            || $this->moneyMath->compare(
                $exchange->getBaseNativeReplacementAmount(),
                $baseNativeAmount
            ) !== 0
            || $this->moneyMath->compare(
                $exchange->getBalanceAmount(),
                $balance
            ) !== 0
        ) {
            throw new InvariantViolationException(
                __('The ordered replacement financial snapshot conflicts with its native order.')
            );
        }

        return false;
    }

    private function recordReadyHistory(
        int $exchangeId,
        int $actorId,
        string $approvedAmount,
        string $fromBalance,
        string $toBalance,
        ?string $comment
    ): void {
        $history = $this->historyFactory->create();
        $history->setExchangeId($exchangeId)
            ->setAction('replacement_order_prepared')
            ->setStatusDimension(StateDimension::REPLACEMENT)
            ->setFromValue(
                sprintf(
                    'status=%s;balance=%s',
                    ReplacementStatus::PENDING,
                    $fromBalance
                )
            )->setToValue(
                sprintf(
                    'status=%s;approved=%s;balance=%s',
                    ReplacementStatus::READY,
                    $approvedAmount,
                    $toBalance
                )
            )->setActorType(ActorType::ADMIN)
            ->setActorId($actorId)
            ->setComment($comment);
        $this->historyResource->save($history);
    }

    private function recordOrderHistory(
        int $exchangeId,
        int $actorId,
        OrderInterface $order,
        string $fromStatus,
        string $fromNative,
        string $toNative,
        string $fromBalance,
        string $toBalance,
        string $expectedAmount,
        ?string $comment
    ): void {
        $history = $this->historyFactory->create();
        $history->setExchangeId($exchangeId)
            ->setAction('native_replacement_order_reconciled')
            ->setStatusDimension(StateDimension::REPLACEMENT)
            ->setFromValue(
                sprintf(
                    'status=%s;native=%s;balance=%s',
                    $fromStatus,
                    $fromNative,
                    $fromBalance
                )
            )->setToValue(
                sprintf(
                    'status=%s;order=%s;expected=%s;native=%s;balance=%s',
                    ReplacementStatus::ORDERED,
                    (string)$order->getIncrementId(),
                    $expectedAmount,
                    $toNative,
                    $toBalance
                )
            )->setActorType(ActorType::ADMIN)
            ->setActorId($actorId)
            ->setComment($comment);
        $this->historyResource->save($history);
    }

    private function assertIntent(string $expected, string $actual): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $expected)
            || !hash_equals($expected, $actual)
        ) {
            throw new InvariantViolationException(
                __('The replacement intent changed between durable saga steps.')
            );
        }
    }

    private function operationKey(int $exchangeId): string
    {
        return sprintf(
            'sales-exchange:replacement-order:v1:%d',
            $exchangeId
        );
    }

    private function normalizeComment(?string $comment): ?string
    {
        if ($comment === null) {
            return null;
        }
        $comment = trim($comment);
        if (mb_strlen($comment) > 1000) {
            throw new InvariantViolationException(
                __('An action comment cannot exceed 1000 characters.')
            );
        }

        return $comment === '' ? null : $comment;
    }
}
