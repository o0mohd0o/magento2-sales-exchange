<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\Creditmemo;

use Bonlineco\SalesExchange\Api\BalanceCalculatorInterface;
use Bonlineco\SalesExchange\Api\ConfigInterface;
use Bonlineco\SalesExchange\Api\CreateCreditmemoInterface;
use Bonlineco\SalesExchange\Api\Data\DocumentLinkInterface;
use Bonlineco\SalesExchange\Api\Data\ExchangeInterface;
use Bonlineco\SalesExchange\Api\Data\ReturnItemInterface;
use Bonlineco\SalesExchange\Api\DocumentType;
use Bonlineco\SalesExchange\Api\ExchangeRepositoryInterface;
use Bonlineco\SalesExchange\Api\History\ActorType;
use Bonlineco\SalesExchange\Api\Status\ExchangeStatus;
use Bonlineco\SalesExchange\Api\Status\ReturnStatus;
use Bonlineco\SalesExchange\Api\Status\SettlementStatus;
use Bonlineco\SalesExchange\Api\Status\StateDimension;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\DocumentLink;
use Bonlineco\SalesExchange\Model\DocumentLinkFactory;
use Bonlineco\SalesExchange\Model\DocumentLinkWriter;
use Bonlineco\SalesExchange\Model\Exchange;
use Bonlineco\SalesExchange\Model\ExchangeFactory;
use Bonlineco\SalesExchange\Model\HistoryFactory;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Bonlineco\SalesExchange\Model\NativeReplacementProjection;
use Bonlineco\SalesExchange\Model\ResourceModel\AllocationGuard;
use Bonlineco\SalesExchange\Model\ResourceModel\DocumentLink as DocumentLinkResource;
use Bonlineco\SalesExchange\Model\ResourceModel\Exchange as ExchangeResource;
use Bonlineco\SalesExchange\Model\ResourceModel\History as HistoryResource;
use Bonlineco\SalesExchange\Model\ResourceModel\ReturnItem as ReturnItemResource;
use Bonlineco\SalesExchange\Model\ReturnItemFactory;
use Bonlineco\SalesExchange\Model\VersionGuard;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Magento\Sales\Api\Data\CreditmemoCommentCreationInterface;
use Magento\Sales\Api\Data\CreditmemoCommentCreationInterfaceFactory;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\RefundOrderInterface;
use Magento\Sales\Model\Order\CreditmemoDocumentFactory;
use Magento\Sales\Model\OrderMutexInterface;
use Psr\Log\LoggerInterface;

/**
 * Transactionally create one offline credit memo and hand off return allocation.
 *
 * Magento's order mutex is deliberately the outer transaction. It locks the
 * native order before this command locks exchange rows, previews the exact
 * credit memo, invokes RefundOrderInterface, and persists every module record.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
 */
class CreateCreditmemo implements CreateCreditmemoInterface
{
    private ConfigInterface $config;

    private ExchangeRepositoryInterface $exchangeRepository;

    private ExchangeResource $exchangeResource;

    private ExchangeFactory $exchangeFactory;

    private ReturnItemResource $returnItemResource;

    private ReturnItemFactory $returnItemFactory;

    private AllocationGuard $allocationGuard;

    private DocumentLinkResource $documentLinkResource;

    private DocumentLinkFactory $documentLinkFactory;

    private DocumentLinkWriter $documentLinkWriter;

    private HistoryFactory $historyFactory;

    private HistoryResource $historyResource;

    private OrderMutexInterface $orderMutex;

    private PreviewOrderLoader $previewOrderLoader;

    private Planner $planner;

    private RequestBuilder $requestBuilder;

    private CreditmemoDocumentFactory $creditmemoDocumentFactory;

    private DocumentValidator $documentValidator;

    private RefundOrderInterface $refundOrder;

    private CreditmemoRepositoryInterface $creditmemoRepository;

    private CreditmemoCommentCreationInterfaceFactory $commentFactory;

    private ExecutionContext $executionContext;

    private ReturnCreditProjection $returnCreditProjection;

    private BalanceCalculatorInterface $balanceCalculator;

    private NativeReplacementProjection $nativeReplacementProjection;

    private VersionGuard $versionGuard;

    private DecimalMath $moneyMath;

    private DecimalMath $quantityMath;

    private SerializerInterface $serializer;

    private ManagerInterface $eventManager;

    private LoggerInterface $logger;

    public function __construct(
        ConfigInterface $config,
        ExchangeRepositoryInterface $exchangeRepository,
        ExchangeResource $exchangeResource,
        ExchangeFactory $exchangeFactory,
        ReturnItemResource $returnItemResource,
        ReturnItemFactory $returnItemFactory,
        AllocationGuard $allocationGuard,
        DocumentLinkResource $documentLinkResource,
        DocumentLinkFactory $documentLinkFactory,
        DocumentLinkWriter $documentLinkWriter,
        HistoryFactory $historyFactory,
        HistoryResource $historyResource,
        OrderMutexInterface $orderMutex,
        PreviewOrderLoader $previewOrderLoader,
        Planner $planner,
        RequestBuilder $requestBuilder,
        CreditmemoDocumentFactory $creditmemoDocumentFactory,
        DocumentValidator $documentValidator,
        RefundOrderInterface $refundOrder,
        CreditmemoRepositoryInterface $creditmemoRepository,
        CreditmemoCommentCreationInterfaceFactory $commentFactory,
        ExecutionContext $executionContext,
        ReturnCreditProjection $returnCreditProjection,
        BalanceCalculatorInterface $balanceCalculator,
        NativeReplacementProjection $nativeReplacementProjection,
        VersionGuard $versionGuard,
        DecimalMath $moneyMath,
        DecimalMath $quantityMath,
        SerializerInterface $serializer,
        ManagerInterface $eventManager,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->exchangeRepository = $exchangeRepository;
        $this->exchangeResource = $exchangeResource;
        $this->exchangeFactory = $exchangeFactory;
        $this->returnItemResource = $returnItemResource;
        $this->returnItemFactory = $returnItemFactory;
        $this->allocationGuard = $allocationGuard;
        $this->documentLinkResource = $documentLinkResource;
        $this->documentLinkFactory = $documentLinkFactory;
        $this->documentLinkWriter = $documentLinkWriter;
        $this->historyFactory = $historyFactory;
        $this->historyResource = $historyResource;
        $this->orderMutex = $orderMutex;
        $this->previewOrderLoader = $previewOrderLoader;
        $this->planner = $planner;
        $this->requestBuilder = $requestBuilder;
        $this->creditmemoDocumentFactory = $creditmemoDocumentFactory;
        $this->documentValidator = $documentValidator;
        $this->refundOrder = $refundOrder;
        $this->creditmemoRepository = $creditmemoRepository;
        $this->commentFactory = $commentFactory;
        $this->executionContext = $executionContext;
        $this->returnCreditProjection = $returnCreditProjection;
        $this->balanceCalculator = $balanceCalculator;
        $this->nativeReplacementProjection = $nativeReplacementProjection;
        $this->versionGuard = $versionGuard;
        $this->moneyMath = $moneyMath;
        $this->quantityMath = $quantityMath;
        $this->serializer = $serializer;
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
        $initial = $this->exchangeRepository->getById($exchangeId);
        $orderId = $initial->getOriginalOrderId();
        $operationKey = $this->operationKey($exchangeId, $expectedVersion);

        try {
            /** @var array{link: DocumentLinkInterface, creditmemo: CreditmemoInterface, created: bool, quantities: array<int, string>} $result */
            $result = $this->orderMutex->execute(
                $orderId,
                \Closure::fromCallable([$this, 'executeLocked']),
                [
                    $exchangeId,
                    $orderId,
                    $expectedVersion,
                    $operationKey,
                    $actorId,
                    $comment,
                ]
            );
        } catch (\Throwable $exception) {
            if ($exception instanceof LocalizedException) {
                throw $exception;
            }
            throw new CouldNotSaveException(
                __('The exchange credit memo could not be created.'),
                $exception
            );
        }

        if ($result['created']) {
            try {
                $this->eventManager->dispatch(
                    'bonlineco_sales_exchange_creditmemo_created',
                    [
                        'exchange_id' => $exchangeId,
                        'document_link' => $result['link'],
                        'creditmemo' => $result['creditmemo'],
                        'quantities' => $result['quantities'],
                    ]
                );
            } catch (\Throwable $exception) {
                // The credit memo and module audit records are already durable.
                $this->logger->critical($exception);
            }
        }

        return $result['link'];
    }

    /**
     * Called only inside the outer Magento sales-order mutex transaction.
     *
     * @return array{link: DocumentLinkInterface, creditmemo: CreditmemoInterface, created: bool, quantities: array<int, string>}
     */
    private function executeLocked(
        int $exchangeId,
        int $orderId,
        int $expectedVersion,
        string $operationKey,
        int $actorId,
        ?string $comment
    ): array {
        $exchangeRow = $this->exchangeResource->getDataForUpdate($exchangeId);
        if ($exchangeRow === null) {
            throw new NoSuchEntityException(
                __('No exchange case exists for ID "%1".', $exchangeId)
            );
        }
        if ((int)$exchangeRow[ExchangeInterface::ORIGINAL_ORDER_ID] !== $orderId) {
            throw new InvariantViolationException(
                __('The exchange original order changed while the operation was being locked.')
            );
        }
        $returnRows = $this->returnItemResource->getRowsByExchangeIdForUpdate($exchangeId);
        foreach ($returnRows as $row) {
            $this->allocationGuard->lock((int)$row[ReturnItemInterface::ORDER_ITEM_ID]);
        }

        $existing = $this->documentLinkResource->getByOperationKeyForUpdate($operationKey);
        if ($existing !== null) {
            return $this->replay($exchangeRow, $returnRows, $existing);
        }

        $nextVersion = $this->versionGuard->assertCurrentAndIncrement(
            $expectedVersion,
            (int)$exchangeRow[ExchangeInterface::VERSION],
            'exchange case'
        );
        $this->assertEligible($exchangeRow);
        $order = $this->previewOrderLoader->execute($orderId);
        $this->assertOrderSnapshots($order, $exchangeRow);
        $plan = $this->planner->execute($order, $returnRows);
        $quantities = $plan->getQuantitiesByOrderItem();
        if ($quantities === []) {
            // A defensive guard against Magento's empty-array means refund-all behavior.
            throw new InvariantViolationException(
                __('An exchange credit memo requires at least one positive accepted line.')
            );
        }
        ksort($quantities, SORT_NUMERIC);
        $itemQuantitiesJson = $this->serializer->serialize($quantities);
        $items = $this->requestBuilder->buildItems($plan);
        $arguments = $this->requestBuilder->buildArguments($plan, $operationKey);
        $nativeComment = $this->createNativeComment(
            (string)$exchangeRow[ExchangeInterface::INCREMENT_ID],
            $operationKey
        );
        $expectedAmount = $this->returnCreditProjection->getOutstandingEstimate($returnRows);
        /** @var CreditmemoInterface $creditmemo */
        $creditmemo = $this->executionContext->execute(
            $operationKey,
            function () use (
                $order,
                $items,
                $nativeComment,
                $arguments,
                $exchangeRow,
                $expectedAmount,
                $plan,
                $orderId,
                $operationKey
            ): CreditmemoInterface {
                $preview = $this->creditmemoDocumentFactory->createFromOrder(
                    $order,
                    $items,
                    $nativeComment,
                    false,
                    $arguments
                );
                $this->documentValidator->assertPreview(
                    $preview,
                    $order,
                    (string)$exchangeRow[ExchangeInterface::CURRENCY_CODE],
                    (string)$exchangeRow[ExchangeInterface::BASE_CURRENCY_CODE],
                    $expectedAmount,
                    $plan
                );
                $previewSnapshot = $this->documentValidator->executionSnapshot($preview);

                $creditmemoId = (int)$this->refundOrder->execute(
                    $orderId,
                    $items,
                    false,
                    false,
                    $nativeComment,
                    $arguments
                );
                if ($creditmemoId <= 0) {
                    throw new CouldNotSaveException(
                        __('Magento did not return a native credit memo ID.')
                    );
                }
                $creditmemo = $this->creditmemoRepository->get($creditmemoId);
                $this->executionContext->assertTrustedRefund(
                    $creditmemo,
                    $creditmemoId,
                    $operationKey
                );
                // Preview and execution may be intercepted independently.
                $this->documentValidator->assertPreview(
                    $creditmemo,
                    $order,
                    (string)$exchangeRow[ExchangeInterface::CURRENCY_CODE],
                    (string)$exchangeRow[ExchangeInterface::BASE_CURRENCY_CODE],
                    $expectedAmount,
                    $plan
                );
                $this->documentValidator->assertExecutionSnapshot(
                    $creditmemo,
                    $previewSnapshot
                );

                return $creditmemo;
            }
        );
        $totals = $this->documentValidator->snapshot(
            $creditmemo,
            $orderId,
            (string)$exchangeRow[ExchangeInterface::CURRENCY_CODE],
            (string)$exchangeRow[ExchangeInterface::BASE_CURRENCY_CODE]
        );
        $updatedRows = $this->handoffQuantities($returnRows, $plan);
        $nativeAmount = $this->moneyMath->add(
            (string)$exchangeRow[ExchangeInterface::NATIVE_RETURN_CREDIT_AMOUNT],
            $totals['amount']
        );
        $baseNativeAmount = $this->moneyMath->add(
            (string)$exchangeRow[ExchangeInterface::BASE_NATIVE_RETURN_CREDIT_AMOUNT],
            $totals['base_amount']
        );
        $projectedCredit = $this->returnCreditProjection->execute($nativeAmount, $updatedRows);
        $balance = $this->balanceCalculator->execute(
            $this->nativeReplacementProjection->execute(
                (string)$exchangeRow[ExchangeInterface::REPLACEMENT_STATUS],
                (string)$exchangeRow[ExchangeInterface::REPLACEMENT_AMOUNT],
                (string)$exchangeRow[ExchangeInterface::SHIPPING_AMOUNT],
                (string)$exchangeRow[ExchangeInterface::NATIVE_REPLACEMENT_AMOUNT]
            ),
            '0.0000',
            (string)$exchangeRow[ExchangeInterface::FEE_AMOUNT],
            $projectedCredit
        );

        $link = $this->createLink(
            $exchangeId,
            $operationKey,
            $itemQuantitiesJson,
            $expectedAmount,
            $creditmemo,
            $totals
        );
        $this->persistExchangeReconciliation(
            $exchangeRow,
            $nextVersion,
            $nativeAmount,
            $baseNativeAmount,
            $balance
        );
        $this->recordHistory(
            $exchangeId,
            $actorId,
            $creditmemo,
            (string)$exchangeRow[ExchangeInterface::NATIVE_RETURN_CREDIT_AMOUNT],
            $nativeAmount,
            (string)$exchangeRow[ExchangeInterface::BALANCE_AMOUNT],
            $balance,
            $expectedAmount,
            $totals['amount'],
            $comment
        );

        return [
            'link' => $link,
            'creditmemo' => $creditmemo,
            'created' => true,
            'quantities' => $quantities,
        ];
    }

    /**
     * @param array<string, mixed> $exchangeRow
     */
    private function assertEligible(array $exchangeRow): void
    {
        $storeId = $exchangeRow[ExchangeInterface::STORE_ID] === null
            ? null
            : (int)$exchangeRow[ExchangeInterface::STORE_ID];
        if (!$this->config->isEnabled($storeId)) {
            throw new InvariantViolationException(
                __('Sales exchanges are disabled for the original order store.')
            );
        }
        if ((string)$exchangeRow[ExchangeInterface::EXCHANGE_STATUS] !== ExchangeStatus::IN_PROGRESS
            || !in_array(
                (string)$exchangeRow[ExchangeInterface::RETURN_STATUS],
                [ReturnStatus::ACCEPTED, ReturnStatus::PARTIALLY_ACCEPTED],
                true
            )
            || (string)$exchangeRow[ExchangeInterface::SETTLEMENT_STATUS]
                !== SettlementStatus::PENDING
        ) {
            throw new InvariantViolationException(
                __(
                    'An offline credit memo requires an in-progress exchange with '
                    . 'a finalized accepted return and pending settlement.'
                )
            );
        }
    }

    /**
     * @param array<string, mixed> $exchangeRow
     */
    private function assertOrderSnapshots(OrderInterface $order, array $exchangeRow): void
    {
        if ((int)$order->getEntityId() !== (int)$exchangeRow[ExchangeInterface::ORIGINAL_ORDER_ID]
            || (string)$order->getOrderCurrencyCode()
                !== (string)$exchangeRow[ExchangeInterface::CURRENCY_CODE]
            || (string)$order->getBaseCurrencyCode()
                !== (string)$exchangeRow[ExchangeInterface::BASE_CURRENCY_CODE]
        ) {
            throw new InvariantViolationException(
                __('The original order no longer matches the exchange identity snapshots.')
            );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function handoffQuantities(array $rows, Plan $plan): array
    {
        $updates = $plan->getReturnItemUpdates();
        foreach ($rows as &$row) {
            $returnItemId = (int)$row[ReturnItemInterface::ENTITY_ID];
            if (!isset($updates[$returnItemId])) {
                continue;
            }
            $nextCredited = $this->quantityMath->add(
                $updates[$returnItemId]['credited_qty'],
                $updates[$returnItemId]['quantity']
            );
            if ($this->quantityMath->compare(
                $nextCredited,
                (string)$row[ReturnItemInterface::ACCEPTED_QTY]
            ) > 0) {
                throw new InvariantViolationException(
                    __('Native credit handoff cannot exceed accepted quantity.')
                );
            }
            $item = $this->returnItemFactory->create();
            $item->setData($row);
            $item->setCreditedQty($nextCredited)
                ->setVersion(
                    $this->versionGuard->assertCurrentAndIncrement(
                        (int)$row[ReturnItemInterface::VERSION],
                        (int)$row[ReturnItemInterface::VERSION],
                        'return item'
                    )
                );
            $this->returnItemResource->save($item);
            $row[ReturnItemInterface::CREDITED_QTY] = $nextCredited;
            $row[ReturnItemInterface::VERSION] = $item->getVersion();
        }
        unset($row);

        return $rows;
    }

    /**
     * @param array{amount: string, base_amount: string} $totals
     */
    private function createLink(
        int $exchangeId,
        string $operationKey,
        string $itemQuantitiesJson,
        string $expectedAmount,
        CreditmemoInterface $creditmemo,
        array $totals
    ): DocumentLinkInterface {
        $link = $this->documentLinkFactory->create();
        $link->setExchangeId($exchangeId)
            ->setDocumentType(DocumentType::CREDITMEMO)
            ->setDocumentId((int)$creditmemo->getEntityId())
            ->setIncrementId((string)$creditmemo->getIncrementId())
            ->setOperationKey($operationKey)
            ->setItemQuantitiesJson($itemQuantitiesJson)
            ->setSnapshotHash(
                $this->documentValidator->persistentFingerprint($creditmemo)
            )
            ->setAmount($totals['amount'])
            ->setExpectedAmount($expectedAmount)
            ->setBaseAmount($totals['base_amount'])
            ->setCurrencyCode((string)$creditmemo->getOrderCurrencyCode())
            ->setBaseCurrencyCode((string)$creditmemo->getBaseCurrencyCode())
            ->setDocumentStatus((string)$creditmemo->getState());

        return $this->documentLinkWriter->append($link);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function persistExchangeReconciliation(
        array $row,
        int $nextVersion,
        string $nativeAmount,
        string $baseNativeAmount,
        string $balance
    ): void {
        /** @var Exchange $exchange */
        $exchange = $this->exchangeFactory->create();
        $exchange->setData($row);
        $exchange->setNativeReturnCreditAmount($nativeAmount)
            ->setBaseNativeReturnCreditAmount($baseNativeAmount)
            ->setBalanceAmount($balance)
            ->setVersion($nextVersion);
        $this->exchangeResource->save($exchange);
    }

    private function recordHistory(
        int $exchangeId,
        int $actorId,
        CreditmemoInterface $creditmemo,
        string $fromNative,
        string $toNative,
        string $fromBalance,
        string $toBalance,
        string $expectedAmount,
        string $actualAmount,
        ?string $comment
    ): void {
        $history = $this->historyFactory->create();
        $history->setExchangeId($exchangeId)
            ->setAction('native_credit_reconciled')
            ->setStatusDimension(StateDimension::SETTLEMENT)
            ->setFromValue(sprintf('native=%s;balance=%s', $fromNative, $fromBalance))
            ->setToValue(
                sprintf(
                    'creditmemo=%s;expected=%s;actual=%s;native=%s;balance=%s',
                    (string)$creditmemo->getIncrementId(),
                    $expectedAmount,
                    $actualAmount,
                    $toNative,
                    $toBalance
                )
            )
            ->setActorType(ActorType::ADMIN)
            ->setActorId($actorId)
            ->setComment($comment);
        $this->historyResource->save($history);
    }

    /**
     * @param array<string, mixed> $exchangeRow
     * @param array<int, array<string, mixed>> $returnRows
     * @param array<string, mixed> $linkRow
     * @return array{link: DocumentLinkInterface, creditmemo: CreditmemoInterface, created: bool, quantities: array<int, string>}
     */
    private function replay(array $exchangeRow, array $returnRows, array $linkRow): array
    {
        if ((int)$linkRow[DocumentLinkInterface::EXCHANGE_ID]
                !== (int)$exchangeRow[ExchangeInterface::ENTITY_ID]
            || (string)$linkRow[DocumentLinkInterface::DOCUMENT_TYPE] !== DocumentType::CREDITMEMO
        ) {
            throw new InvariantViolationException(
                __('The credit memo operation key is linked to another intent.')
            );
        }
        $quantities = $this->decodeQuantities(
            (string)($linkRow[DocumentLinkInterface::ITEM_QUANTITIES_JSON] ?? '')
        );
        $this->assertReplayHandoff($returnRows, $quantities);
        $plan = new Plan($quantities, [], []);
        $order = $this->previewOrderLoader->execute(
            (int)$exchangeRow[ExchangeInterface::ORIGINAL_ORDER_ID]
        );
        $creditmemo = $this->creditmemoRepository->get(
            (int)$linkRow[DocumentLinkInterface::DOCUMENT_ID]
        );
        $this->documentValidator->assertPersisted(
            $creditmemo,
            $order,
            (string)$exchangeRow[ExchangeInterface::CURRENCY_CODE],
            (string)$exchangeRow[ExchangeInterface::BASE_CURRENCY_CODE],
            (string)$linkRow[DocumentLinkInterface::EXPECTED_AMOUNT],
            $plan
        );
        $this->documentValidator->assertPersistentFingerprint(
            $creditmemo,
            (string)($linkRow[DocumentLinkInterface::SNAPSHOT_HASH] ?? '')
        );
        $totals = $this->documentValidator->snapshot(
            $creditmemo,
            (int)$exchangeRow[ExchangeInterface::ORIGINAL_ORDER_ID],
            (string)$exchangeRow[ExchangeInterface::CURRENCY_CODE],
            (string)$exchangeRow[ExchangeInterface::BASE_CURRENCY_CODE]
        );
        if ($this->moneyMath->compare(
            $totals['amount'],
            (string)$linkRow[DocumentLinkInterface::AMOUNT]
        ) !== 0 || $this->moneyMath->compare(
            $totals['base_amount'],
            (string)$linkRow[DocumentLinkInterface::BASE_AMOUNT]
        ) !== 0
            || (int)$creditmemo->getEntityId()
                !== (int)$linkRow[DocumentLinkInterface::DOCUMENT_ID]
            || (string)$creditmemo->getIncrementId()
                !== (string)$linkRow[DocumentLinkInterface::INCREMENT_ID]
            || (string)$creditmemo->getState()
                !== (string)$linkRow[DocumentLinkInterface::DOCUMENT_STATUS]
            || (string)$creditmemo->getOrderCurrencyCode()
                !== (string)$linkRow[DocumentLinkInterface::CURRENCY_CODE]
            || (string)$creditmemo->getBaseCurrencyCode()
                !== (string)$linkRow[DocumentLinkInterface::BASE_CURRENCY_CODE]
        ) {
            throw new InvariantViolationException(
                __('The linked native credit memo no longer matches its audit snapshot.')
            );
        }
        /** @var DocumentLink $link */
        $link = $this->documentLinkFactory->create();
        $link->setData($linkRow);

        return [
            'link' => $link,
            'creditmemo' => $creditmemo,
            'created' => false,
            'quantities' => $quantities,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function decodeQuantities(string $json): array
    {
        $decoded = $json === '' ? null : $this->serializer->unserialize($json);
        if (!is_array($decoded) || $decoded === []) {
            throw new InvariantViolationException(
                __('The native document line snapshot is missing or invalid.')
            );
        }
        $quantities = [];
        foreach ($decoded as $orderItemId => $quantity) {
            if (!is_scalar($quantity) || (int)$orderItemId <= 0) {
                throw new InvariantViolationException(
                    __('The native document line snapshot is invalid.')
                );
            }
            $normalized = $this->quantityMath->assertNonNegative(
                (string)$quantity,
                'Linked credit memo quantity'
            );
            if ($this->quantityMath->compare($normalized, '0') <= 0) {
                throw new InvariantViolationException(
                    __('Linked credit memo quantities must be positive.')
                );
            }
            $quantities[(int)$orderItemId] = $normalized;
        }
        ksort($quantities, SORT_NUMERIC);

        return $quantities;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string> $quantities
     */
    private function assertReplayHandoff(array $rows, array $quantities): void
    {
        $creditedByOrderItem = [];
        foreach ($rows as $row) {
            $creditedByOrderItem[(int)$row[ReturnItemInterface::ORDER_ITEM_ID]] =
                (string)($row[ReturnItemInterface::CREDITED_QTY] ?? '0');
        }
        foreach ($quantities as $orderItemId => $quantity) {
            if (!isset($creditedByOrderItem[$orderItemId])
                || $this->quantityMath->compare(
                    $creditedByOrderItem[$orderItemId],
                    $quantity
                ) < 0
            ) {
                throw new InvariantViolationException(
                    __('The linked credit memo quantity handoff is incomplete.')
                );
            }
        }
    }

    private function createNativeComment(
        string $exchangeIncrementId,
        string $operationKey
    ): CreditmemoCommentCreationInterface {
        /** @var CreditmemoCommentCreationInterface $comment */
        $comment = $this->commentFactory->create();
        $comment->setComment(
            (string)__(
                'Created by exchange %1 (%2).',
                $exchangeIncrementId,
                $operationKey
            )
        )->setIsVisibleOnFront(0);

        return $comment;
    }

    private function operationKey(int $exchangeId, int $expectedVersion): string
    {
        return sprintf('creditmemo:exchange:%d:version:%d', $exchangeId, $expectedVersion);
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
