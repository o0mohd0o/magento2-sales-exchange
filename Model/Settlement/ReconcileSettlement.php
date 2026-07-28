<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\Settlement;

use Bonlineco\SalesExchange\Api\Data\DocumentLinkInterface;
use Bonlineco\SalesExchange\Api\Data\ExchangeInterface;
use Bonlineco\SalesExchange\Api\Data\SettlementInterface;
use Bonlineco\SalesExchange\Api\DocumentLinkRepositoryInterface;
use Bonlineco\SalesExchange\Api\DocumentType;
use Bonlineco\SalesExchange\Api\ExchangeRepositoryInterface;
use Bonlineco\SalesExchange\Api\History\ActorType;
use Bonlineco\SalesExchange\Api\ReconcileSettlementInterface;
use Bonlineco\SalesExchange\Api\StateTransitionGuardInterface;
use Bonlineco\SalesExchange\Api\Status\ExchangeStatus;
use Bonlineco\SalesExchange\Api\Status\ReplacementStatus;
use Bonlineco\SalesExchange\Api\Status\SettlementStatus;
use Bonlineco\SalesExchange\Api\Status\StateDimension;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\DocumentLink;
use Bonlineco\SalesExchange\Model\DocumentLinkFactory;
use Bonlineco\SalesExchange\Model\DocumentLinkWriter;
use Bonlineco\SalesExchange\Model\CompletionValidator;
use Bonlineco\SalesExchange\Model\Exchange;
use Bonlineco\SalesExchange\Model\ExchangeFactory;
use Bonlineco\SalesExchange\Model\HistoryFactory;
use Bonlineco\SalesExchange\Model\ReplacementOrder\Marker;
use Bonlineco\SalesExchange\Model\ReplacementOrder\NativeOrderValidator;
use Bonlineco\SalesExchange\Model\ResourceModel\DocumentLink as DocumentLinkResource;
use Bonlineco\SalesExchange\Model\ResourceModel\Exchange as ExchangeResource;
use Bonlineco\SalesExchange\Model\ResourceModel\History as HistoryResource;
use Bonlineco\SalesExchange\Model\ResourceModel\ReplacementItem as ReplacementItemResource;
use Bonlineco\SalesExchange\Model\ResourceModel\ReturnItem as ReturnItemResource;
use Bonlineco\SalesExchange\Model\ResourceModel\Settlement as SettlementResource;
use Bonlineco\SalesExchange\Model\VersionGuard;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Model\AbstractModel;
use Magento\Sales\Api\Data\InvoiceCommentCreationInterface;
use Magento\Sales\Api\Data\InvoiceInterface;
use Magento\Sales\Api\Data\InvoiceItemCreationInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\InvoiceOrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\OrderMutexInterface;
use Psr\Log\LoggerInterface;

/**
 * Create the native full invoice and reconcile one immutable ledger.
 *
 * Invoice, immutable module link, ledger, history, and exchange projection
 * share one sales-resource transaction under deterministic original-order
 * then replacement-order locks. An unlinked native invoice is deliberately
 * rejected as ambiguous; the predictable comment is audit text, not identity.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
 * @SuppressWarnings(PHPMD.TooManyFields)
 */
class ReconcileSettlement implements ReconcileSettlementInterface
{
    private ExchangeRepositoryInterface $exchangeRepository;

    private DocumentLinkRepositoryInterface $documentLinkRepository;

    private ExchangeResource $exchangeResource;

    private ExchangeFactory $exchangeFactory;

    private ReturnItemResource $returnItemResource;

    private ReplacementItemResource $replacementItemResource;

    private DocumentLinkResource $documentLinkResource;

    private SettlementResource $settlementResource;

    private DocumentLinkFactory $documentLinkFactory;

    private DocumentLinkWriter $documentLinkWriter;

    private HistoryFactory $historyFactory;

    private HistoryResource $historyResource;

    private OrderMutexInterface $orderMutex;

    private OrderRepositoryInterface $orderRepository;

    private InvoiceOrderInterface $invoiceOrder;

    private Planner $planner;

    private EligibilityValidator $eligibilityValidator;

    private NativeReturnCreditValidator $returnCreditValidator;

    private NativeOrderValidator $nativeOrderValidator;

    private InvoiceRequestBuilder $invoiceRequestBuilder;

    private NativeInvoiceValidator $invoiceValidator;

    private InvoiceLookup $invoiceLookup;

    private LedgerWriter $ledgerWriter;

    private OperationKeys $operationKeys;

    private VersionGuard $versionGuard;

    private StateTransitionGuardInterface $transitionGuard;

    private CompletionValidator $completionValidator;

    private ManagerInterface $eventManager;

    private LoggerInterface $logger;

    public function __construct(
        ExchangeRepositoryInterface $exchangeRepository,
        DocumentLinkRepositoryInterface $documentLinkRepository,
        ExchangeResource $exchangeResource,
        ExchangeFactory $exchangeFactory,
        ReturnItemResource $returnItemResource,
        ReplacementItemResource $replacementItemResource,
        DocumentLinkResource $documentLinkResource,
        SettlementResource $settlementResource,
        DocumentLinkFactory $documentLinkFactory,
        DocumentLinkWriter $documentLinkWriter,
        HistoryFactory $historyFactory,
        HistoryResource $historyResource,
        OrderMutexInterface $orderMutex,
        OrderRepositoryInterface $orderRepository,
        InvoiceOrderInterface $invoiceOrder,
        Planner $planner,
        EligibilityValidator $eligibilityValidator,
        NativeReturnCreditValidator $returnCreditValidator,
        NativeOrderValidator $nativeOrderValidator,
        InvoiceRequestBuilder $invoiceRequestBuilder,
        NativeInvoiceValidator $invoiceValidator,
        InvoiceLookup $invoiceLookup,
        LedgerWriter $ledgerWriter,
        OperationKeys $operationKeys,
        VersionGuard $versionGuard,
        StateTransitionGuardInterface $transitionGuard,
        CompletionValidator $completionValidator,
        ManagerInterface $eventManager,
        LoggerInterface $logger
    ) {
        $this->exchangeRepository = $exchangeRepository;
        $this->documentLinkRepository = $documentLinkRepository;
        $this->exchangeResource = $exchangeResource;
        $this->exchangeFactory = $exchangeFactory;
        $this->returnItemResource = $returnItemResource;
        $this->replacementItemResource = $replacementItemResource;
        $this->documentLinkResource = $documentLinkResource;
        $this->settlementResource = $settlementResource;
        $this->documentLinkFactory = $documentLinkFactory;
        $this->documentLinkWriter = $documentLinkWriter;
        $this->historyFactory = $historyFactory;
        $this->historyResource = $historyResource;
        $this->orderMutex = $orderMutex;
        $this->orderRepository = $orderRepository;
        $this->invoiceOrder = $invoiceOrder;
        $this->planner = $planner;
        $this->eligibilityValidator = $eligibilityValidator;
        $this->returnCreditValidator = $returnCreditValidator;
        $this->nativeOrderValidator = $nativeOrderValidator;
        $this->invoiceRequestBuilder = $invoiceRequestBuilder;
        $this->invoiceValidator = $invoiceValidator;
        $this->invoiceLookup = $invoiceLookup;
        $this->ledgerWriter = $ledgerWriter;
        $this->operationKeys = $operationKeys;
        $this->versionGuard = $versionGuard;
        $this->transitionGuard = $transitionGuard;
        $this->completionValidator = $completionValidator;
        $this->eventManager = $eventManager;
        $this->logger = $logger;
    }

    public function execute(
        int $exchangeId,
        int $expectedVersion,
        int $actorId,
        ?string $externalReference = null,
        ?string $comment = null
    ): ExchangeInterface {
        if ($exchangeId <= 0 || $expectedVersion <= 0 || $actorId <= 0) {
            throw new InvariantViolationException(
                __('A valid exchange, version, and admin actor are required.')
            );
        }
        $comment = $this->normalizeComment($comment);
        $initial = $this->exchangeRepository->getById($exchangeId);
        $originalOrderId = $initial->getOriginalOrderId();
        $replacementOrderId = $this->resolveReplacementOrderId($initial);
        $lockIds = [$originalOrderId];
        if ($replacementOrderId !== null) {
            $lockIds[] = $replacementOrderId;
        }

        try {
            /** @var array{
             *     replay: ExchangeInterface|null,
             *     needs_invoice: bool,
             *     replacement_order_id: int|null,
             *     items: InvoiceItemCreationInterface[],
             *     native_comment: InvoiceCommentCreationInterface|null
             * } $prepared
             */
            $prepared = $this->withOrderLocks(
                $lockIds,
                function () use (
                    $exchangeId,
                    $originalOrderId,
                    $replacementOrderId,
                    $expectedVersion,
                    $externalReference
                ): array {
                    return $this->prepareLocked(
                        $exchangeId,
                        $originalOrderId,
                        $replacementOrderId,
                        $expectedVersion,
                        $externalReference
                    );
                }
            );
            if ($prepared['replay'] !== null) {
                return $prepared['replay'];
            }
            /** @var array{
             *     exchange: ExchangeInterface,
             *     invoice: InvoiceInterface|null,
             *     entries: SettlementInterface[],
             *     changed: bool
             * } $result
             */
            $result = $this->withOrderLocks(
                $lockIds,
                function () use (
                    $exchangeId,
                    $originalOrderId,
                    $replacementOrderId,
                    $expectedVersion,
                    $actorId,
                    $externalReference,
                    $comment,
                    $prepared
                ): array {
                    return $this->reconcileLocked(
                        $exchangeId,
                        $originalOrderId,
                        $replacementOrderId,
                        $expectedVersion,
                        $actorId,
                        $externalReference,
                        $comment,
                        $prepared['items'],
                        $prepared['native_comment']
                    );
                }
            );
        } catch (\Throwable $exception) {
            if ($exception instanceof LocalizedException) {
                throw $exception;
            }
            throw new CouldNotSaveException(
                __('The exchange settlement could not be reconciled.'),
                $exception
            );
        }

        if ($result['changed']) {
            try {
                $this->eventManager->dispatch(
                    'bonlineco_sales_exchange_settlement_reconciled',
                    [
                        'exchange' => $result['exchange'],
                        'invoice' => $result['invoice'],
                        'settlement_entries' => $result['entries'],
                    ]
                );
            } catch (\Throwable $exception) {
                // The native invoice, ledger, audit link, and exchange are durable.
                $this->logger->critical($exception);
            }
        }

        return $result['exchange'];
    }

    /**
     * @return array{
     *     replay: ExchangeInterface|null,
     *     needs_invoice: bool,
     *     replacement_order_id: int|null,
     *     items: InvoiceItemCreationInterface[],
     *     native_comment: InvoiceCommentCreationInterface|null
     * }
     */
    private function prepareLocked(
        int $exchangeId,
        int $originalOrderId,
        ?int $lockedReplacementOrderId,
        int $expectedVersion,
        ?string $externalReference
    ): array {
        $state = $this->loadState($exchangeId, $originalOrderId);
        $plan = $this->planner->execute($state['exchange'], $externalReference);
        if (in_array(
            $state['exchange']->getSettlementStatus(),
            SettlementStatus::terminal(),
            true
        )) {
            $validated = $this->validateTerminalState(
                $state,
                $plan,
                $lockedReplacementOrderId
            );

            return [
                'replay' => $validated['exchange'],
                'needs_invoice' => false,
                'replacement_order_id' => $validated['replacement_order_id'],
                'items' => [],
                'native_comment' => null,
            ];
        }
        $this->versionGuard->assertCurrentAndIncrement(
            $expectedVersion,
            $state['exchange']->getVersion(),
            'exchange case'
        );
        $validated = $this->validateOpenState(
            $state,
            $plan,
            $lockedReplacementOrderId
        );
        if ($validated['invoice'] !== null || !$plan->requiresInvoice()) {
            return [
                'replay' => null,
                'needs_invoice' => false,
                'replacement_order_id' => $validated['replacement_order_id'],
                'items' => [],
                'native_comment' => null,
            ];
        }
        $order = $validated['replacement_order'];
        if (!$order instanceof OrderInterface) {
            throw new InvariantViolationException(
                __('The replacement order is unavailable for native invoicing.')
            );
        }

        return [
            'replay' => null,
            'needs_invoice' => true,
            'replacement_order_id' => (int)$order->getEntityId(),
            'items' => $this->invoiceRequestBuilder->buildItems($order),
            'native_comment' => $this->invoiceRequestBuilder->buildComment(
                $state['exchange']->getIncrementId(),
                $this->operationKeys->invoice($exchangeId)
            ),
        ];
    }

    /**
     * @return array{
     *     exchange: ExchangeInterface,
     *     invoice: InvoiceInterface|null,
     *     entries: SettlementInterface[],
     *     changed: bool
     * }
     */
    private function reconcileLocked(
        int $exchangeId,
        int $originalOrderId,
        ?int $lockedReplacementOrderId,
        int $expectedVersion,
        int $actorId,
        ?string $externalReference,
        ?string $comment,
        array $invoiceItems,
        ?InvoiceCommentCreationInterface $nativeComment
    ): array {
        $state = $this->loadState($exchangeId, $originalOrderId);
        $plan = $this->planner->execute($state['exchange'], $externalReference);
        if (in_array(
            $state['exchange']->getSettlementStatus(),
            SettlementStatus::terminal(),
            true
        )) {
            $validated = $this->validateTerminalState(
                $state,
                $plan,
                $lockedReplacementOrderId
            );

            return [
                'exchange' => $validated['exchange'],
                'invoice' => $validated['invoice'],
                'entries' => $validated['entries'],
                'changed' => false,
            ];
        }
        $nextVersion = $this->versionGuard->assertCurrentAndIncrement(
            $expectedVersion,
            $state['exchange']->getVersion(),
            'exchange case'
        );
        $validated = $this->validateOpenState(
            $state,
            $plan,
            $lockedReplacementOrderId
        );
        $createdInvoiceId = null;
        if ($plan->requiresInvoice() && $validated['invoice'] === null) {
            if ($lockedReplacementOrderId === null
                || $invoiceItems === []
                || $nativeComment === null
            ) {
                throw new InvariantViolationException(
                    __('The canonical native invoice request is incomplete.')
                );
            }
            $createdInvoiceId = (int)$this->invoiceOrder->execute(
                $lockedReplacementOrderId,
                false,
                $invoiceItems,
                false,
                false,
                $nativeComment
            );
            if ($createdInvoiceId <= 0) {
                throw new CouldNotSaveException(
                    __('Magento did not return a native invoice ID.')
                );
            }
            $state = $this->loadState($exchangeId, $originalOrderId);
            $plan = $this->planner->execute(
                $state['exchange'],
                $externalReference
            );
            $this->versionGuard->assertCurrentAndIncrement(
                $expectedVersion,
                $state['exchange']->getVersion(),
                'exchange case'
            );
            $validated = $this->validateOpenState(
                $state,
                $plan,
                $lockedReplacementOrderId,
                true
            );
            if ($validated['invoice'] === null
                || (int)$validated['invoice']->getEntityId() !== $createdInvoiceId
            ) {
                throw new InvariantViolationException(
                    __('Magento returned a different native replacement invoice.')
                );
            }
        }
        if ($plan->requiresInvoice() && $validated['invoice'] === null) {
            throw new InvariantViolationException(
                __('The exact native replacement invoice was not committed.')
            );
        }
        if ($validated['invoice'] !== null && $validated['invoice_link'] === null) {
            $this->appendInvoiceLink(
                $exchangeId,
                $validated['invoice'],
                $validated['invoice_snapshot'],
                $plan
            );
        }
        $entries = $this->ledgerWriter->appendPlan($plan, $comment);
        $settlementRows = $this->settlementResource
            ->getRowsByExchangeIdForUpdate($exchangeId);
        $exchange = $this->persistExchange(
            $state['row'],
            $plan,
            $nextVersion,
            $state['return_rows'],
            $state['replacement_rows'],
            $settlementRows
        );
        $this->recordHistory(
            $exchange,
            $actorId,
            $validated['invoice'],
            $comment,
            (string)$state['row'][ExchangeInterface::EXCHANGE_STATUS]
        );

        return [
            'exchange' => $exchange,
            'invoice' => $validated['invoice'],
            'entries' => $entries,
            'changed' => true,
        ];
    }

    /**
     * @param array{
     *     row: array<string, mixed>,
     *     exchange: ExchangeInterface,
     *     return_rows: array<int, array<string, mixed>>,
     *     replacement_rows: array<int, array<string, mixed>>,
     *     document_rows: array<int, array<string, mixed>>,
     *     settlement_rows: array<int, array<string, mixed>>,
     *     original_order: OrderInterface
     * } $state
     * @return array{
     *     replacement_order_id: int|null,
     *     replacement_order: OrderInterface|null,
     *     invoice: InvoiceInterface|null,
     *     invoice_link: array<string, mixed>|null,
     *     invoice_snapshot: array<string, string>
     * }
     */
    private function validateOpenState(
        array $state,
        Plan $plan,
        ?int $lockedReplacementOrderId,
        bool $allowNewUnlinkedInvoice = false
    ): array {
        $commercial = $this->loadCommercialState(
            $state,
            $plan,
            $lockedReplacementOrderId,
            $allowNewUnlinkedInvoice
        );
        $recovery = $state['settlement_rows'] !== []
            || $commercial['invoice_link'] !== null
            || $commercial['invoice'] !== null;
        $this->eligibilityValidator->execute(
            $state['exchange'],
            $state['return_rows'],
            $state['replacement_rows'],
            $state['document_rows'],
            !$recovery
        );
        $this->returnCreditValidator->execute(
            $state['exchange'],
            $state['original_order'],
            $state['return_rows'],
            $state['document_rows']
        );
        if ($state['settlement_rows'] !== []) {
            $this->ledgerWriter->assertCompatiblePartial(
                $plan,
                $state['settlement_rows']
            );
        }

        return $commercial;
    }

    /**
     * @param array{
     *     row: array<string, mixed>,
     *     exchange: ExchangeInterface,
     *     return_rows: array<int, array<string, mixed>>,
     *     replacement_rows: array<int, array<string, mixed>>,
     *     document_rows: array<int, array<string, mixed>>,
     *     settlement_rows: array<int, array<string, mixed>>,
     *     original_order: OrderInterface
     * } $state
     * @return array{
     *     exchange: ExchangeInterface,
     *     replacement_order_id: int|null,
     *     invoice: InvoiceInterface|null,
     *     entries: SettlementInterface[]
     * }
     */
    private function validateTerminalState(
        array $state,
        Plan $plan,
        ?int $lockedReplacementOrderId
    ): array {
        if ($state['exchange']->getSettlementStatus() !== $plan->getTargetStatus()) {
            throw new InvariantViolationException(
                __('The terminal settlement status conflicts with its canonical balance.')
            );
        }
        $this->eligibilityValidator->assertTerminal(
            $state['exchange'],
            $state['return_rows'],
            $state['replacement_rows'],
            $state['document_rows']
        );
        $this->returnCreditValidator->execute(
            $state['exchange'],
            $state['original_order'],
            $state['return_rows'],
            $state['document_rows']
        );
        $commercial = $this->loadCommercialState(
            $state,
            $plan,
            $lockedReplacementOrderId
        );
        if ($plan->requiresInvoice()
            && ($commercial['invoice'] === null
                || $commercial['invoice_link'] === null)
        ) {
            throw new InvariantViolationException(
                __('The reconciled settlement is missing its native invoice audit link.')
            );
        }
        $entries = $this->ledgerWriter->assertExact(
            $plan,
            $state['settlement_rows']
        );

        return [
            'exchange' => $state['exchange'],
            'replacement_order_id' => $commercial['replacement_order_id'],
            'invoice' => $commercial['invoice'],
            'entries' => $entries,
        ];
    }

    /**
     * @param array{
     *     row: array<string, mixed>,
     *     exchange: ExchangeInterface,
     *     return_rows: array<int, array<string, mixed>>,
     *     replacement_rows: array<int, array<string, mixed>>,
     *     document_rows: array<int, array<string, mixed>>,
     *     settlement_rows: array<int, array<string, mixed>>,
     *     original_order: OrderInterface
     * } $state
     * @return array{
     *     replacement_order_id: int|null,
     *     replacement_order: OrderInterface|null,
     *     invoice: InvoiceInterface|null,
     *     invoice_link: array<string, mixed>|null,
     *     invoice_snapshot: array<string, string>
     * }
     */
    private function loadCommercialState(
        array $state,
        Plan $plan,
        ?int $lockedReplacementOrderId,
        bool $allowNewUnlinkedInvoice = false
    ): array {
        if (!$plan->requiresInvoice()) {
            if ($lockedReplacementOrderId !== null) {
                throw new InvariantViolationException(
                    __('The replacement lock set conflicts with a cancelled intent.')
                );
            }

            return [
                'replacement_order_id' => null,
                'replacement_order' => null,
                'invoice' => null,
                'invoice_link' => null,
                'invoice_snapshot' => [],
            ];
        }
        $orderLink = $this->findSingleDocument(
            $state['document_rows'],
            DocumentType::ORDER
        );
        if ($orderLink === null) {
            throw new InvariantViolationException(
                __('The native replacement order audit link is missing.')
            );
        }
        $replacementOrderId = (int)$orderLink[DocumentLinkInterface::DOCUMENT_ID];
        if ($replacementOrderId <= 0
            || $replacementOrderId !== $lockedReplacementOrderId
        ) {
            throw new InvariantViolationException(
                __('The replacement order changed outside the deterministic lock set.')
            );
        }
        $order = $this->orderRepository->get($replacementOrderId);
        if (!$order instanceof AbstractModel) {
            throw new InvariantViolationException(
                __('The native replacement order implementation is unsupported.')
            );
        }
        $intentHash = $order->getData(Marker::INTENT_HASH);
        if (!is_string($intentHash)) {
            throw new InvariantViolationException(
                __('The native replacement order intent marker is missing.')
            );
        }
        $orderSnapshot = $this->nativeOrderValidator->snapshot(
            $order,
            $state['original_order'],
            $state['exchange'],
            $state['replacement_rows'],
            $intentHash
        );
        $this->assertOrderLink(
            $orderLink,
            $state['exchange'],
            $order,
            $orderSnapshot
        );
        if ($orderSnapshot['amount'] !== $plan->getReplacementAmount()
            || $orderSnapshot['base_amount'] !== $plan->getBaseReplacementAmount()
        ) {
            throw new InvariantViolationException(
                __('The live replacement order totals differ from the settlement plan.')
            );
        }

        $invoiceLink = $this->findSingleDocument(
            $state['document_rows'],
            DocumentType::INVOICE
        );
        $invoice = $this->invoiceLookup->find($replacementOrderId);
        $invoiceSnapshot = [];
        if ($invoice !== null) {
            $operationKey = $this->operationKeys->invoice($plan->getExchangeId());
            if ($invoiceLink === null && !$allowNewUnlinkedInvoice) {
                throw new InvariantViolationException(
                    __('An unlinked native invoice already exists for the replacement order.')
                );
            }
            if ($invoiceLink === null
                && !$this->invoiceValidator->hasOperationMarker(
                    $invoice,
                    $operationKey
                )
            ) {
                throw new InvariantViolationException(
                    __('Magento did not retain the canonical exchange invoice audit comment.')
                );
            }
            $quantities = $this->invoiceRequestBuilder->quantities($order);
            $invoiceSnapshot = $this->invoiceValidator->snapshot(
                $invoice,
                $order,
                $plan,
                $quantities
            );
            if ($invoiceLink !== null) {
                $this->assertInvoiceLink(
                    $invoiceLink,
                    $invoice,
                    $invoiceSnapshot,
                    $plan
                );
            }
        } elseif ($invoiceLink !== null) {
            throw new InvariantViolationException(
                __('The linked native replacement invoice no longer exists.')
            );
        }

        return [
            'replacement_order_id' => $replacementOrderId,
            'replacement_order' => $order,
            'invoice' => $invoice,
            'invoice_link' => $invoiceLink,
            'invoice_snapshot' => $invoiceSnapshot,
        ];
    }

    /**
     * @return array{
     *     row: array<string, mixed>,
     *     exchange: ExchangeInterface,
     *     return_rows: array<int, array<string, mixed>>,
     *     replacement_rows: array<int, array<string, mixed>>,
     *     document_rows: array<int, array<string, mixed>>,
     *     settlement_rows: array<int, array<string, mixed>>,
     *     original_order: OrderInterface
     * }
     */
    private function loadState(int $exchangeId, int $originalOrderId): array
    {
        $row = $this->exchangeResource->getDataForUpdate($exchangeId);
        if ($row === null) {
            throw new NoSuchEntityException(
                __('No exchange case exists for ID "%1".', $exchangeId)
            );
        }
        if ((int)$row[ExchangeInterface::ORIGINAL_ORDER_ID] !== $originalOrderId) {
            throw new InvariantViolationException(
                __('The original order changed outside the deterministic lock set.')
            );
        }
        /** @var Exchange $exchange */
        $exchange = $this->exchangeFactory->create();
        $exchange->setData($row);

        return [
            'row' => $row,
            'exchange' => $exchange,
            'return_rows' => $this->returnItemResource
                ->getRowsByExchangeIdForUpdate($exchangeId),
            'replacement_rows' => $this->replacementItemResource
                ->getRowsByExchangeIdForUpdate($exchangeId),
            'document_rows' => $this->documentLinkResource
                ->getRowsByExchangeIdForUpdate($exchangeId),
            'settlement_rows' => $this->settlementResource
                ->getRowsByExchangeIdForUpdate($exchangeId),
            'original_order' => $this->orderRepository->get($originalOrderId),
        ];
    }

    private function resolveReplacementOrderId(
        ExchangeInterface $exchange
    ): ?int {
        if (!in_array(
            $exchange->getReplacementStatus(),
            [
                ReplacementStatus::ORDERED,
                ReplacementStatus::SHIPPED,
                ReplacementStatus::DELIVERED,
            ],
            true
        )) {
            return null;
        }
        try {
            $link = $this->documentLinkRepository->getByOperationKey(
                $this->operationKeys->replacementOrder(
                    (int)$exchange->getEntityId()
                )
            );
        } catch (NoSuchEntityException $exception) {
            throw new InvariantViolationException(
                __('The native replacement order audit link is missing.'),
                $exception
            );
        }
        if ($link->getExchangeId() !== (int)$exchange->getEntityId()
            || $link->getDocumentType() !== DocumentType::ORDER
            || $link->getDocumentId() <= 0
        ) {
            throw new InvariantViolationException(
                __('The replacement order operation key is linked to another intent.')
            );
        }

        return $link->getDocumentId();
    }

    /**
     * Acquire native order locks in ascending entity-ID order.
     *
     * @return mixed
     */
    private function withOrderLocks(array $orderIds, callable $operation)
    {
        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));
        sort($orderIds, SORT_NUMERIC);
        $runner = function (int $offset) use (&$runner, $orderIds, $operation) {
            if (!isset($orderIds[$offset])) {
                return $operation();
            }
            if ($orderIds[$offset] <= 0) {
                throw new InvariantViolationException(
                    __('A valid native order lock identity is required.')
                );
            }

            return $this->orderMutex->execute(
                $orderIds[$offset],
                static function () use ($runner, $offset) {
                    return $runner($offset + 1);
                }
            );
        };

        return $runner(0);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    private function findSingleDocument(array $rows, string $type): ?array
    {
        $matches = [];
        foreach ($rows as $row) {
            if ((string)($row[DocumentLinkInterface::DOCUMENT_TYPE] ?? '') === $type) {
                $matches[] = $row;
            }
        }
        if (count($matches) > 1) {
            throw new InvariantViolationException(
                __('The exchange has more than one "%1" document link.', $type)
            );
        }

        return $matches[0] ?? null;
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
    private function assertOrderLink(
        array $row,
        ExchangeInterface $exchange,
        OrderInterface $order,
        array $snapshot
    ): void {
        $matches = (int)$row[DocumentLinkInterface::EXCHANGE_ID]
                === (int)$exchange->getEntityId()
            && (string)$row[DocumentLinkInterface::DOCUMENT_TYPE]
                === DocumentType::ORDER
            && (int)$row[DocumentLinkInterface::DOCUMENT_ID]
                === (int)$order->getEntityId()
            && (string)$row[DocumentLinkInterface::INCREMENT_ID]
                === (string)$order->getIncrementId()
            && (string)$row[DocumentLinkInterface::OPERATION_KEY]
                === $this->operationKeys->replacementOrder(
                    (int)$exchange->getEntityId()
                )
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
            && $snapshot['amount'] === (string)$row[DocumentLinkInterface::AMOUNT]
            && $snapshot['base_amount']
                === (string)$row[DocumentLinkInterface::BASE_AMOUNT]
            && $snapshot['expected_amount']
                === (string)$row[DocumentLinkInterface::EXPECTED_AMOUNT];
        if (!$matches) {
            throw new InvariantViolationException(
                __('The linked native replacement order differs from its canonical snapshot.')
            );
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $snapshot
     */
    private function assertInvoiceLink(
        array $row,
        InvoiceInterface $invoice,
        array $snapshot,
        Plan $plan
    ): void {
        $matches = (int)$row[DocumentLinkInterface::EXCHANGE_ID]
                === $plan->getExchangeId()
            && (string)$row[DocumentLinkInterface::DOCUMENT_TYPE]
                === DocumentType::INVOICE
            && (int)$row[DocumentLinkInterface::DOCUMENT_ID]
                === (int)$invoice->getEntityId()
            && (string)$row[DocumentLinkInterface::INCREMENT_ID]
                === (string)$invoice->getIncrementId()
            && (string)$row[DocumentLinkInterface::OPERATION_KEY]
                === $this->operationKeys->invoice($plan->getExchangeId())
            && (string)$row[DocumentLinkInterface::ITEM_QUANTITIES_JSON]
                === $snapshot['item_quantities_json']
            && is_string($row[DocumentLinkInterface::SNAPSHOT_HASH] ?? null)
            && hash_equals(
                $snapshot['snapshot_hash'],
                (string)$row[DocumentLinkInterface::SNAPSHOT_HASH]
            )
            && (string)$row[DocumentLinkInterface::CURRENCY_CODE]
                === $plan->getCurrencyCode()
            && (string)$row[DocumentLinkInterface::BASE_CURRENCY_CODE]
                === $plan->getBaseCurrencyCode()
            && (string)$row[DocumentLinkInterface::DOCUMENT_STATUS]
                === (string)$invoice->getState()
            && (string)$row[DocumentLinkInterface::AMOUNT]
                === $snapshot['amount']
            && (string)$row[DocumentLinkInterface::BASE_AMOUNT]
                === $snapshot['base_amount']
            && (string)$row[DocumentLinkInterface::EXPECTED_AMOUNT]
                === $plan->getReplacementAmount();
        if (!$matches) {
            throw new InvariantViolationException(
                __('The linked native invoice differs from its canonical snapshot.')
            );
        }
    }

    /**
     * @param array<string, string> $snapshot
     */
    private function appendInvoiceLink(
        int $exchangeId,
        InvoiceInterface $invoice,
        array $snapshot,
        Plan $plan
    ): DocumentLinkInterface {
        /** @var DocumentLink $link */
        $link = $this->documentLinkFactory->create();
        $link->setExchangeId($exchangeId)
            ->setDocumentType(DocumentType::INVOICE)
            ->setDocumentId((int)$invoice->getEntityId())
            ->setIncrementId((string)$invoice->getIncrementId())
            ->setOperationKey($this->operationKeys->invoice($exchangeId))
            ->setItemQuantitiesJson($snapshot['item_quantities_json'])
            ->setSnapshotHash($snapshot['snapshot_hash'])
            ->setAmount($snapshot['amount'])
            ->setExpectedAmount($plan->getReplacementAmount())
            ->setBaseAmount($snapshot['base_amount'])
            ->setCurrencyCode($plan->getCurrencyCode())
            ->setBaseCurrencyCode($plan->getBaseCurrencyCode())
            ->setDocumentStatus((string)$invoice->getState());

        return $this->documentLinkWriter->append($link);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function persistExchange(
        array $row,
        Plan $plan,
        int $nextVersion,
        array $returnRows,
        array $replacementRows,
        array $settlementRows
    ): ExchangeInterface {
        /** @var Exchange $exchange */
        $exchange = $this->exchangeFactory->create();
        $exchange->setData($row);
        $this->transitionGuard->executeSettlementReconciliation(
            (string)$row[ExchangeInterface::SETTLEMENT_STATUS],
            $plan->getTargetStatus()
        );
        $exchange->setSettlementStatus($plan->getTargetStatus())
            ->setBalanceAmount($plan->getBalanceAmount())
            ->setVersion($nextVersion);
        if (in_array(
            $exchange->getReplacementStatus(),
            [ReplacementStatus::CANCELLED, ReplacementStatus::DELIVERED],
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
                $exchange->getExchangeStatus(),
                ExchangeStatus::COMPLETED
            );
            $exchange->setExchangeStatus(ExchangeStatus::COMPLETED);
        }
        $this->exchangeResource->save($exchange);

        return $exchange;
    }

    private function recordHistory(
        ExchangeInterface $exchange,
        int $actorId,
        ?InvoiceInterface $invoice,
        ?string $comment,
        string $fromExchangeStatus
    ): void {
        $history = $this->historyFactory->create();
        $history->setExchangeId((int)$exchange->getEntityId())
            ->setAction('settlement_reconciled')
            ->setStatusDimension(StateDimension::SETTLEMENT)
            ->setFromValue(SettlementStatus::PENDING)
            ->setToValue(
                sprintf(
                    '%s;balance=%s;invoice=%s',
                    $exchange->getSettlementStatus(),
                    $exchange->getBalanceAmount(),
                    $invoice === null ? 'none' : (string)$invoice->getIncrementId()
                )
            )
            ->setActorType(ActorType::ADMIN)
            ->setActorId($actorId)
            ->setComment($comment);
        $this->historyResource->save($history);
        if ($fromExchangeStatus !== $exchange->getExchangeStatus()) {
            $completion = $this->historyFactory->create();
            $completion->setExchangeId((int)$exchange->getEntityId())
                ->setAction('exchange_completed')
                ->setStatusDimension(StateDimension::EXCHANGE)
                ->setFromValue($fromExchangeStatus)
                ->setToValue($exchange->getExchangeStatus())
                ->setActorType(ActorType::ADMIN)
                ->setActorId($actorId)
                ->setComment($comment);
            $this->historyResource->save($completion);
        }
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
