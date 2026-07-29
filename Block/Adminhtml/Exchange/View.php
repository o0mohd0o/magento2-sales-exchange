<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Block\Adminhtml\Exchange;

use Bonlineco\SalesExchange\Api\ConfigInterface;
use Bonlineco\SalesExchange\Api\Data\DocumentLinkInterface;
use Bonlineco\SalesExchange\Api\Data\ExchangeInterface;
use Bonlineco\SalesExchange\Api\Data\HistoryInterface;
use Bonlineco\SalesExchange\Api\Data\ReplacementItemInterface;
use Bonlineco\SalesExchange\Api\Data\ReturnItemInterface;
use Bonlineco\SalesExchange\Api\Data\SettlementInterface;
use Bonlineco\SalesExchange\Api\DocumentLinkRepositoryInterface;
use Bonlineco\SalesExchange\Api\DocumentType;
use Bonlineco\SalesExchange\Api\ExchangeRepositoryInterface;
use Bonlineco\SalesExchange\Api\HistoryRepositoryInterface;
use Bonlineco\SalesExchange\Api\History\ActorType;
use Bonlineco\SalesExchange\Api\ReplacementItemRepositoryInterface;
use Bonlineco\SalesExchange\Api\ReturnItemRepositoryInterface;
use Bonlineco\SalesExchange\Api\SettlementRepositoryInterface;
use Bonlineco\SalesExchange\Api\Status\ExchangeStatus;
use Bonlineco\SalesExchange\Api\Status\ReplacementStatus;
use Bonlineco\SalesExchange\Api\Status\ReturnStatus;
use Bonlineco\SalesExchange\Api\Status\SettlementStatus;
use Bonlineco\SalesExchange\Model\Authorization\ExchangeFinancialAuthorization;
use Bonlineco\SalesExchange\Model\Authorization\ReplacementCancellationAuthorization;
use Bonlineco\SalesExchange\Model\Authorization\ReplacementOrderAuthorization;
use Bonlineco\SalesExchange\Model\Authorization\SettlementAuthorization;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Bonlineco\SalesExchange\Model\Settlement\OperationKeys;
use Bonlineco\SalesExchange\Model\Workflow\AdminActionMap;
use Magento\Backend\Block\Widget\Container;
use Magento\Backend\Block\Widget\Context;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Exchange case admin presentation and guarded workflow controls.
 *
 * The controller and domain services remain authoritative for every workflow
 * transition. These checks intentionally hide actions that are clearly
 * unavailable, while the POST handlers re-check ACL, version, and invariants.
 */
class View extends Container
{
    private const ACL_APPROVE = 'Bonlineco_SalesExchange::approve';
    private const ACL_WAREHOUSE = 'Bonlineco_SalesExchange::warehouse';
    private const ACL_CANCEL = 'Bonlineco_SalesExchange::cancel';
    private const ACL_CREDITMEMO_VIEW = 'Magento_Sales::sales_creditmemo';

    private ExchangeRepositoryInterface $exchangeRepository;

    private ReturnItemRepositoryInterface $returnItemRepository;

    private ReplacementItemRepositoryInterface $replacementItemRepository;

    private SettlementRepositoryInterface $settlementRepository;

    private HistoryRepositoryInterface $historyRepository;

    private DocumentLinkRepositoryInterface $documentLinkRepository;

    private OrderRepositoryInterface $orderRepository;

    private SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory;

    private PriceCurrencyInterface $priceCurrency;

    private ConfigInterface $config;

    private ExchangeFinancialAuthorization $financialAuthorization;

    private ReplacementOrderAuthorization $replacementOrderAuthorization;

    private ReplacementCancellationAuthorization $replacementCancellationAuthorization;

    private SettlementAuthorization $settlementAuthorization;

    private OperationKeys $settlementOperationKeys;

    private DecimalMath $quantityMath;

    private ?ExchangeInterface $exchange = null;

    private bool $exchangeLoaded = false;

    private ?OrderInterface $originalOrder = null;

    private bool $originalOrderLoaded = false;

    /**
     * @var ReturnItemInterface[]|null
     */
    private ?array $returnItems = null;

    /**
     * @var ReplacementItemInterface[]|null
     */
    private ?array $replacementItems = null;

    /**
     * @var SettlementInterface[]|null
     */
    private ?array $settlements = null;

    /**
     * @var HistoryInterface[]|null
     */
    private ?array $historyEntries = null;

    /**
     * @var DocumentLinkInterface[]|null
     */
    private ?array $creditmemoLinks = null;

    private ?DocumentLinkInterface $replacementOrderLink = null;

    private bool $replacementOrderLinkLoaded = false;

    /**
     * @var DocumentLinkInterface[]|null
     */
    private ?array $settlementDocumentLinks = null;

    /**
     * @param Context $context
     * @param ExchangeRepositoryInterface $exchangeRepository
     * @param ReturnItemRepositoryInterface $returnItemRepository
     * @param ReplacementItemRepositoryInterface $replacementItemRepository
     * @param SettlementRepositoryInterface $settlementRepository
     * @param HistoryRepositoryInterface $historyRepository
     * @param DocumentLinkRepositoryInterface $documentLinkRepository
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory
     * @param PriceCurrencyInterface $priceCurrency
     * @param ConfigInterface $config
     * @param ExchangeFinancialAuthorization $financialAuthorization
     * @param ReplacementOrderAuthorization $replacementOrderAuthorization
     * @param DecimalMath $quantityMath
     * @param ReplacementCancellationAuthorization $replacementCancellationAuthorization
     * @param SettlementAuthorization $settlementAuthorization
     * @param OperationKeys $settlementOperationKeys
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        ExchangeRepositoryInterface $exchangeRepository,
        ReturnItemRepositoryInterface $returnItemRepository,
        ReplacementItemRepositoryInterface $replacementItemRepository,
        SettlementRepositoryInterface $settlementRepository,
        HistoryRepositoryInterface $historyRepository,
        DocumentLinkRepositoryInterface $documentLinkRepository,
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory,
        PriceCurrencyInterface $priceCurrency,
        ConfigInterface $config,
        ExchangeFinancialAuthorization $financialAuthorization,
        ReplacementOrderAuthorization $replacementOrderAuthorization,
        DecimalMath $quantityMath,
        ReplacementCancellationAuthorization $replacementCancellationAuthorization,
        SettlementAuthorization $settlementAuthorization,
        OperationKeys $settlementOperationKeys,
        array $data = []
    ) {
        $this->exchangeRepository = $exchangeRepository;
        $this->returnItemRepository = $returnItemRepository;
        $this->replacementItemRepository = $replacementItemRepository;
        $this->settlementRepository = $settlementRepository;
        $this->historyRepository = $historyRepository;
        $this->documentLinkRepository = $documentLinkRepository;
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilderFactory = $searchCriteriaBuilderFactory;
        $this->priceCurrency = $priceCurrency;
        $this->config = $config;
        $this->financialAuthorization = $financialAuthorization;
        $this->replacementOrderAuthorization = $replacementOrderAuthorization;
        $this->replacementCancellationAuthorization =
            $replacementCancellationAuthorization;
        $this->settlementAuthorization = $settlementAuthorization;
        $this->settlementOperationKeys = $settlementOperationKeys;
        $this->quantityMath = $quantityMath;
        parent::__construct($context, $data);
    }

    /**
     * Add only actions that are valid for the visible workflow snapshot.
     *
     * @return $this
     */
    protected function _prepareLayout()
    {
        $this->addRedirectButton(
            'back',
            (string)__('Back'),
            $this->getUrl('salesexchange/exchange/index'),
            'back',
            10
        );

        if ($this->canApprove()) {
            $this->addPostButton(
                'approve',
                (string)__('Approve'),
                'salesexchange/exchange/approve',
                (string)__('Approve Exchange'),
                (string)__('Approve this exchange case and reserve its requested return quantities?'),
                'primary',
                20
            );
        }
        if ($this->canAuthorizeReturn()) {
            $this->addPostButton(
                'authorize',
                (string)__('Authorize Return'),
                'salesexchange/exchange/authorize',
                (string)__('Authorize Return'),
                (string)__('Authorize the customer to return the selected products?'),
                'primary',
                30
            );
        }
        if ($this->canStart()) {
            $this->addPostButton(
                'start',
                (string)__('Start Exchange'),
                'salesexchange/exchange/start',
                (string)__('Start Exchange'),
                (string)__('Move this approved and authorized exchange into progress?'),
                'primary',
                40
            );
        }
        if ($this->canFinalizeInspection()) {
            $this->addPostButton(
                'finalize_inspection',
                (string)__('Finalize Inspection'),
                'salesexchange/exchange/finalizeInspection',
                (string)__('Finalize Inspection'),
                (string)__('Derive the final return outcome from the saved inspection rows?'),
                'primary',
                70
            );
        }
        if ($this->canCreateCreditmemo()) {
            $this->addPostButton(
                'create_creditmemo',
                (string)__('Create Offline Credit Memo'),
                'salesexchange/exchange/createCreditmemo',
                (string)__('Create Offline Credit Memo'),
                (string)__(
                    'Create an offline Magento credit memo for all accepted quantities not yet credited?'
                ),
                'primary',
                75
            );
        }
        if ($this->canCreateReplacementOrder()) {
            $isResume = $this->getExchange()?->getReplacementStatus() === ReplacementStatus::READY;
            $label = $isResume
                ? (string)__('Resume Replacement Order')
                : (string)__('Create Replacement Order');
            $confirmMessage = $isResume
                ? (string)__('Resume native Magento order creation for this replacement?')
                : (string)__('Create the native Magento order for this replacement?');
            $this->addPostButton(
                'create_replacement_order',
                $label,
                'salesexchange/exchange/createReplacementOrder',
                $label,
                $confirmMessage,
                'primary',
                77
            );
        }
        if ($this->canCancelReplacementIntent()) {
            $this->addPostButton(
                'cancel_replacement',
                (string)__('Cancel Unplaced Replacement'),
                'salesexchange/exchange/cancelReplacement',
                (string)__('Cancel Unplaced Replacement'),
                (string)__(
                    'Cancel this unplaced replacement and continue through '
                    . 'refund-only settlement? The prepared quote remains as an inactive audit record.'
                ),
                'action-secondary',
                78
            );
        }
        if ($this->canViewReplacementOrder()) {
            $this->addRedirectButton(
                'view_replacement_order',
                (string)__('View Replacement Order'),
                $this->getReplacementOrderViewUrl(),
                'action-secondary',
                79
            );
        }
        if ($this->canReconcileSettlement()) {
            $this->addRedirectButton(
                'reconcile_settlement',
                (string)__('Reconcile Settlement'),
                '#salesexchange-settlement-action',
                'primary',
                81
            );
        }
        if ($this->canReject()) {
            $this->addPostButton(
                'reject',
                (string)__('Reject Exchange'),
                'salesexchange/exchange/reject',
                (string)__('Reject Exchange'),
                (string)__('Reject this exchange after the returned products were fully rejected?'),
                'action-secondary',
                80
            );
        }
        if ($this->canCancel()) {
            $this->addPostButton(
                'cancel',
                (string)__('Cancel Exchange'),
                'salesexchange/exchange/cancel',
                (string)__('Cancel Exchange'),
                (string)__('Cancel this exchange? This action cannot be undone.'),
                'delete',
                90
            );
        }

        return parent::_prepareLayout();
    }

    public function getExchange(): ?ExchangeInterface
    {
        if ($this->exchangeLoaded) {
            return $this->exchange;
        }

        $this->exchangeLoaded = true;
        $exchangeId = (int)$this->getRequest()->getParam('entity_id');
        if ($exchangeId <= 0) {
            return null;
        }

        try {
            $this->exchange = $this->exchangeRepository->getById($exchangeId);
        } catch (NoSuchEntityException $exception) {
            $this->exchange = null;
        }

        return $this->exchange;
    }

    public function getOriginalOrder(): ?OrderInterface
    {
        if ($this->originalOrderLoaded) {
            return $this->originalOrder;
        }

        $this->originalOrderLoaded = true;
        if (!$this->_authorization->isAllowed(AdminActionMap::ACL_SALES_ORDER_VIEW)) {
            return null;
        }
        $exchange = $this->getExchange();
        if ($exchange === null) {
            return null;
        }

        try {
            $this->originalOrder = $this->orderRepository->get($exchange->getOriginalOrderId());
        } catch (NoSuchEntityException $exception) {
            $this->originalOrder = null;
        }

        return $this->originalOrder;
    }

    public function getOriginalOrderViewUrl(): string
    {
        $order = $this->getOriginalOrder();
        if ($order === null || (int)$order->getEntityId() <= 0) {
            return '';
        }

        return $this->getUrl('sales/order/view', ['order_id' => (int)$order->getEntityId()]);
    }

    /**
     * @return ReturnItemInterface[]
     */
    public function getReturnItems(): array
    {
        if ($this->returnItems !== null) {
            return $this->returnItems;
        }

        $exchange = $this->getExchange();
        if ($exchange === null) {
            return $this->returnItems = [];
        }

        $items = $this->returnItemRepository->getList(
            $this->createExchangeItemCriteria((int)$exchange->getEntityId())
        )->getItems();
        usort(
            $items,
            static fn (ReturnItemInterface $left, ReturnItemInterface $right): int =>
                ($left->getEntityId() ?? 0) <=> ($right->getEntityId() ?? 0)
        );

        return $this->returnItems = $items;
    }

    /**
     * @return ReplacementItemInterface[]
     */
    public function getReplacementItems(): array
    {
        if ($this->replacementItems !== null) {
            return $this->replacementItems;
        }

        $exchange = $this->getExchange();
        if ($exchange === null) {
            return $this->replacementItems = [];
        }

        $items = $this->replacementItemRepository->getList(
            $this->createExchangeItemCriteria((int)$exchange->getEntityId())
        )->getItems();
        usort(
            $items,
            static fn (ReplacementItemInterface $left, ReplacementItemInterface $right): int =>
                ($left->getEntityId() ?? 0) <=> ($right->getEntityId() ?? 0)
        );

        return $this->replacementItems = $items;
    }

    /**
     * @return SettlementInterface[]
     */
    public function getSettlements(): array
    {
        if ($this->settlements !== null) {
            return $this->settlements;
        }

        $exchange = $this->getExchange();
        if ($exchange === null) {
            return $this->settlements = [];
        }

        $items = $this->settlementRepository->getList(
            $this->createExchangeItemCriteria((int)$exchange->getEntityId())
        )->getItems();
        usort(
            $items,
            static fn (SettlementInterface $left, SettlementInterface $right): int =>
                strcmp($left->getCreatedAt() ?? '', $right->getCreatedAt() ?? '')
        );

        return $this->settlements = $items;
    }

    /**
     * @return HistoryInterface[]
     */
    public function getHistoryEntries(): array
    {
        if ($this->historyEntries !== null) {
            return $this->historyEntries;
        }

        $exchange = $this->getExchange();
        if ($exchange === null) {
            return $this->historyEntries = [];
        }

        $items = $this->historyRepository->getList(
            $this->createExchangeItemCriteria((int)$exchange->getEntityId())
        )->getItems();
        usort(
            $items,
            static fn (HistoryInterface $left, HistoryInterface $right): int =>
                strcmp($right->getCreatedAt() ?? '', $left->getCreatedAt() ?? '')
        );

        return $this->historyEntries = $items;
    }

    /**
     * @return DocumentLinkInterface[]
     */
    public function getCreditmemoLinks(): array
    {
        if ($this->creditmemoLinks !== null) {
            return $this->creditmemoLinks;
        }
        if (!$this->canViewNativeCreditmemos()) {
            return $this->creditmemoLinks = [];
        }
        $exchange = $this->getExchange();
        if ($exchange === null || $exchange->getEntityId() === null) {
            return $this->creditmemoLinks = [];
        }

        /** @var SearchCriteriaBuilder $builder */
        $builder = $this->searchCriteriaBuilderFactory->create();
        $items = $this->documentLinkRepository->getList(
            $builder
                ->addFilter(DocumentLinkInterface::EXCHANGE_ID, (int)$exchange->getEntityId())
                ->addFilter(DocumentLinkInterface::DOCUMENT_TYPE, DocumentType::CREDITMEMO)
                ->setPageSize(500)
                ->create()
        )->getItems();
        usort(
            $items,
            static fn (DocumentLinkInterface $left, DocumentLinkInterface $right): int =>
                strcmp($left->getCreatedAt() ?? '', $right->getCreatedAt() ?? '')
        );

        return $this->creditmemoLinks = $items;
    }

    public function getCreditmemoViewUrl(DocumentLinkInterface $documentLink): string
    {
        if (!$this->canViewNativeCreditmemos()
            || $documentLink->getDocumentType() !== DocumentType::CREDITMEMO
            || $documentLink->getDocumentId() <= 0
        ) {
            return '';
        }

        return $this->getUrl(
            'sales/order_creditmemo/view',
            ['creditmemo_id' => $documentLink->getDocumentId()]
        );
    }

    public function getReplacementOrderLink(): ?DocumentLinkInterface
    {
        if ($this->replacementOrderLinkLoaded) {
            return $this->replacementOrderLink;
        }

        $this->replacementOrderLinkLoaded = true;
        if (!$this->canViewNativeReplacementOrder()) {
            return null;
        }
        $exchange = $this->getExchange();
        if ($exchange === null || $exchange->getEntityId() === null) {
            return null;
        }

        /** @var SearchCriteriaBuilder $builder */
        $builder = $this->searchCriteriaBuilderFactory->create();
        $items = $this->documentLinkRepository->getList(
            $builder
                ->addFilter(DocumentLinkInterface::EXCHANGE_ID, (int)$exchange->getEntityId())
                ->addFilter(DocumentLinkInterface::DOCUMENT_TYPE, DocumentType::ORDER)
                ->setPageSize(2)
                ->create()
        )->getItems();
        usort(
            $items,
            static fn (DocumentLinkInterface $left, DocumentLinkInterface $right): int =>
                ($left->getEntityId() ?? 0) <=> ($right->getEntityId() ?? 0)
        );
        $documentLink = reset($items);
        if (!$documentLink instanceof DocumentLinkInterface) {
            return null;
        }

        return $this->replacementOrderLink = $documentLink;
    }

    public function getReplacementOrderViewUrl(): string
    {
        $documentLink = $this->getReplacementOrderLink();
        if ($documentLink === null
            || $documentLink->getDocumentType() !== DocumentType::ORDER
            || $documentLink->getDocumentId() <= 0
        ) {
            return '';
        }

        return $this->getUrl(
            'sales/order/view',
            ['order_id' => $documentLink->getDocumentId()]
        );
    }

    public function formatMoney(string $amount): string
    {
        $exchange = $this->getExchange();
        if ($exchange === null) {
            return $amount;
        }

        return $this->priceCurrency->format(
            $amount,
            false,
            PriceCurrencyInterface::DEFAULT_PRECISION,
            $exchange->getStoreId(),
            $exchange->getCurrencyCode()
        );
    }

    public function formatBaseMoney(string $amount): string
    {
        $exchange = $this->getExchange();
        if ($exchange === null) {
            return $amount;
        }

        return $this->priceCurrency->format(
            $amount,
            false,
            PriceCurrencyInterface::DEFAULT_PRECISION,
            $exchange->getStoreId(),
            $exchange->getBaseCurrencyCode()
        );
    }

    public function getReplacementVariance(): ?string
    {
        $exchange = $this->getExchange();
        if ($exchange === null || !in_array(
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
            $approvedTotal = $this->quantityMath->add(
                $exchange->getReplacementAmount(),
                $exchange->getShippingAmount()
            );

            return $this->quantityMath->subtract(
                $exchange->getNativeReplacementAmount(),
                $approvedTotal
            );
        } catch (LocalizedException $exception) {
            return null;
        }
    }

    public function formatDateTime(?string $date): string
    {
        if ($date === null || $date === '') {
            return (string)__('Not available');
        }

        return $this->_localeDate->formatDateTime(
            $date,
            \IntlDateFormatter::MEDIUM,
            \IntlDateFormatter::SHORT
        );
    }

    /**
     * @return array<int, array{label: Phrase, status: string}>
     */
    public function getWorkflowStatusRows(): array
    {
        $exchange = $this->getExchange();
        if ($exchange === null) {
            return [];
        }

        return [
            [
                'label' => __('Exchange'),
                'status' => $exchange->getExchangeStatus(),
            ],
            [
                'label' => __('Return'),
                'status' => $exchange->getReturnStatus(),
            ],
            [
                'label' => __('Replacement'),
                'status' => $exchange->getReplacementStatus(),
            ],
            [
                'label' => __('Settlement'),
                'status' => $exchange->getSettlementStatus(),
            ],
        ];
    }

    public function getStatusLabel(string $status): Phrase
    {
        return match ($status) {
            ExchangeStatus::DRAFT => __('Draft'),
            ExchangeStatus::PENDING_APPROVAL => __('Pending Approval'),
            ExchangeStatus::APPROVED => __('Approved'),
            ExchangeStatus::IN_PROGRESS => __('In Progress'),
            ExchangeStatus::COMPLETED => __('Completed'),
            ExchangeStatus::REJECTED => __('Rejected'),
            ExchangeStatus::CANCELLED => __('Cancelled'),
            ReturnStatus::AUTHORIZED => __('Authorized'),
            ReturnStatus::IN_TRANSIT => __('In Transit'),
            ReturnStatus::RECEIVED => __('Received'),
            ReturnStatus::INSPECTED => __('Inspected'),
            ReturnStatus::ACCEPTED => __('Accepted'),
            ReturnStatus::PARTIALLY_ACCEPTED => __('Partially Accepted'),
            ReplacementStatus::READY => __('Ready'),
            ReplacementStatus::ORDERED => __('Ordered'),
            ReplacementStatus::SHIPPED => __('Shipped'),
            ReplacementStatus::DELIVERED => __('Delivered'),
            SettlementStatus::PAYMENT_DUE => __('Payment Due'),
            SettlementStatus::REFUND_DUE => __('Refund Due'),
            SettlementStatus::BALANCED => __('Balanced'),
            SettlementStatus::PAYMENT_RECEIVED => __('Payment Received'),
            SettlementStatus::REFUND_ISSUED => __('Refund Issued'),
            SettlementStatus::FAILED => __('Failed'),
            default => __(ucwords(str_replace('_', ' ', $status))),
        };
    }

    public function getStatusCssClass(string $status): string
    {
        if (in_array(
            $status,
            [
                ExchangeStatus::COMPLETED,
                ReturnStatus::ACCEPTED,
                ReturnStatus::PARTIALLY_ACCEPTED,
                ReplacementStatus::DELIVERED,
                SettlementStatus::BALANCED,
                SettlementStatus::PAYMENT_RECEIVED,
                SettlementStatus::REFUND_ISSUED,
                'succeeded',
            ],
            true
        )) {
            return 'success';
        }

        if (in_array(
            $status,
            [ExchangeStatus::REJECTED, ExchangeStatus::CANCELLED, SettlementStatus::FAILED],
            true
        )) {
            return 'error';
        }

        if (in_array($status, [ExchangeStatus::DRAFT, 'pending', ExchangeStatus::PENDING_APPROVAL], true)) {
            return 'pending';
        }

        return 'processing';
    }

    public function getSettlementTypeLabel(string $type): Phrase
    {
        return match ($type) {
            'return_credit' => __('Return Credit'),
            'customer_payment' => __('Customer Payment'),
            'merchant_refund' => __('Merchant Refund'),
            'adjustment' => __('Adjustment'),
            default => __(ucwords(str_replace('_', ' ', $type))),
        };
    }

    public function getCreditmemoStateLabel(?string $state): Phrase
    {
        return match ($state) {
            '1' => __('Pending'),
            '2' => __('Refunded'),
            '3' => __('Canceled'),
            default => __('Unknown'),
        };
    }

    public function getCreditmemoStateCssClass(?string $state): string
    {
        return match ($state) {
            '2' => 'success',
            '3' => 'error',
            default => 'pending',
        };
    }

    public function getHistoryActorLabel(HistoryInterface $entry): Phrase
    {
        $actorLabel = match ($entry->getActorType()) {
            ActorType::ADMIN => __('Admin'),
            ActorType::CUSTOMER => __('Customer'),
            ActorType::INTEGRATION => __('Integration'),
            default => __('System'),
        };

        if ($entry->getActorId() === null) {
            return $actorLabel;
        }

        return __('%1 #%2', $actorLabel, $entry->getActorId());
    }

    public function canApprove(): bool
    {
        $exchange = $this->getExchange();
        return $exchange !== null
            && $this->_authorization->isAllowed(self::ACL_APPROVE)
            && in_array(
                $exchange->getExchangeStatus(),
                [ExchangeStatus::DRAFT, ExchangeStatus::PENDING_APPROVAL],
                true
            );
    }

    public function canAuthorizeReturn(): bool
    {
        $exchange = $this->getExchange();
        return $exchange !== null
            && $this->_authorization->isAllowed(self::ACL_WAREHOUSE)
            && $exchange->getExchangeStatus() === ExchangeStatus::APPROVED
            && $exchange->getReturnStatus() === ReturnStatus::PENDING;
    }

    public function canStart(): bool
    {
        $exchange = $this->getExchange();
        return $exchange !== null
            && $this->_authorization->isAllowed(self::ACL_WAREHOUSE)
            && $exchange->getExchangeStatus() === ExchangeStatus::APPROVED
            && $exchange->getReturnStatus() === ReturnStatus::AUTHORIZED;
    }

    public function canManageReceipt(): bool
    {
        $exchange = $this->getExchange();
        return $exchange !== null
            && $this->_authorization->isAllowed(self::ACL_WAREHOUSE)
            && $exchange->getExchangeStatus() === ExchangeStatus::IN_PROGRESS
            && in_array(
                $exchange->getReturnStatus(),
                [ReturnStatus::AUTHORIZED, ReturnStatus::IN_TRANSIT],
                true
            );
    }

    public function canManageInspection(): bool
    {
        $exchange = $this->getExchange();
        return $exchange !== null
            && $this->_authorization->isAllowed(self::ACL_WAREHOUSE)
            && $exchange->getExchangeStatus() === ExchangeStatus::IN_PROGRESS
            && $exchange->getReturnStatus() === ReturnStatus::RECEIVED;
    }

    public function canFinalizeInspection(): bool
    {
        $exchange = $this->getExchange();
        return $exchange !== null
            && $this->_authorization->isAllowed(self::ACL_WAREHOUSE)
            && $exchange->getExchangeStatus() === ExchangeStatus::IN_PROGRESS
            && $exchange->getReturnStatus() === ReturnStatus::INSPECTED;
    }

    public function canCreateCreditmemo(): bool
    {
        $exchange = $this->getExchange();
        if ($exchange === null
            || !$this->financialAuthorization->isAllowed()
            || !$this->config->isEnabled($exchange->getStoreId())
            || $exchange->getExchangeStatus() !== ExchangeStatus::IN_PROGRESS
            || !in_array(
                $exchange->getReturnStatus(),
                [ReturnStatus::ACCEPTED, ReturnStatus::PARTIALLY_ACCEPTED],
                true
            )
            || $exchange->getSettlementStatus() !== SettlementStatus::PENDING
        ) {
            return false;
        }

        try {
            foreach ($this->getReturnItems() as $item) {
                if ($this->quantityMath->compare(
                    $item->getAcceptedQty(),
                    $item->getCreditedQty()
                ) > 0) {
                    return true;
                }
            }
        } catch (LocalizedException $exception) {
            return false;
        }

        return false;
    }

    public function canViewNativeCreditmemos(): bool
    {
        return $this->_authorization->isAllowed(
            ExchangeFinancialAuthorization::ACL_NATIVE_CREDITMEMO
        ) && $this->_authorization->isAllowed(self::ACL_CREDITMEMO_VIEW);
    }

    public function canCreateReplacementOrder(): bool
    {
        $exchange = $this->getExchange();

        return $exchange !== null
            && $this->replacementOrderAuthorization->isAllowed()
            && $this->config->isEnabled($exchange->getStoreId())
            && $exchange->getExchangeStatus() === ExchangeStatus::IN_PROGRESS
            && in_array(
                $exchange->getReturnStatus(),
                [ReturnStatus::ACCEPTED, ReturnStatus::PARTIALLY_ACCEPTED],
                true
            )
            && in_array(
                $exchange->getReplacementStatus(),
                [ReplacementStatus::PENDING, ReplacementStatus::READY],
                true
            )
            && $exchange->getSettlementStatus() === SettlementStatus::PENDING;
    }

    public function canCancelReplacementIntent(): bool
    {
        $exchange = $this->getExchange();
        if ($exchange === null
            || !$this->replacementCancellationAuthorization->isAllowed()
            || $exchange->getExchangeStatus() !== ExchangeStatus::IN_PROGRESS
            || !in_array(
                $exchange->getReturnStatus(),
                [ReturnStatus::ACCEPTED, ReturnStatus::PARTIALLY_ACCEPTED],
                true
            )
            || !in_array(
                $exchange->getReplacementStatus(),
                [ReplacementStatus::PENDING, ReplacementStatus::READY],
                true
            )
            || $exchange->getSettlementStatus() !== SettlementStatus::PENDING
            || $this->quantityMath->compare(
                $exchange->getNativeReplacementAmount(),
                '0.0000'
            ) !== 0
            || $this->quantityMath->compare(
                $exchange->getBaseNativeReplacementAmount(),
                '0.0000'
            ) !== 0
            || $this->getReplacementOrderLink() !== null
        ) {
            return false;
        }

        try {
            foreach ($this->getReplacementItems() as $item) {
                if ($item->getReplacementOrderItemId() !== null) {
                    return false;
                }
            }
        } catch (LocalizedException $exception) {
            return false;
        }

        return true;
    }

    /**
     * Show the settlement action only for a complete, server-verifiable handoff.
     */
    public function canReconcileSettlement(): bool
    {
        $exchange = $this->getExchange();
        if ($exchange === null
            || !$this->settlementAuthorization->isAllowed()
            || !$this->config->isEnabled($exchange->getStoreId())
            || $exchange->getExchangeStatus() !== ExchangeStatus::IN_PROGRESS
            || !in_array(
                $exchange->getReturnStatus(),
                [ReturnStatus::ACCEPTED, ReturnStatus::PARTIALLY_ACCEPTED],
                true
            )
            || $exchange->getSettlementStatus() !== SettlementStatus::PENDING
        ) {
            return false;
        }

        try {
            if (!$this->hasValidSettlementAmounts($exchange)
                || !$this->hasFullyCreditedReturnItems()
            ) {
                return false;
            }
            $documentLinks = $this->getSettlementDocumentLinks();
            if ($exchange->getReplacementStatus() === ReplacementStatus::CANCELLED) {
                return $this->hasCleanCancelledReplacement(
                    $exchange,
                    $documentLinks
                );
            }

            return in_array(
                $exchange->getReplacementStatus(),
                [
                    ReplacementStatus::ORDERED,
                    ReplacementStatus::SHIPPED,
                    ReplacementStatus::DELIVERED,
                ],
                true
            ) && $this->hasTrustedReplacementDocuments(
                $exchange,
                $documentLinks
            );
        } catch (LocalizedException $exception) {
            return false;
        }
    }

    public function settlementRequiresExternalReference(): bool
    {
        $exchange = $this->getExchange();
        if ($exchange === null) {
            return false;
        }

        try {
            return $this->quantityMath->compare(
                $exchange->getBalanceAmount(),
                '0'
            ) !== 0;
        } catch (LocalizedException $exception) {
            return false;
        }
    }

    public function canViewNativeReplacementOrder(): bool
    {
        return $this->_authorization->isAllowed(AdminActionMap::ACL_VIEW)
            && $this->_authorization->isAllowed(AdminActionMap::ACL_SALES_ORDER_VIEW);
    }

    public function canViewReplacementOrder(): bool
    {
        $exchange = $this->getExchange();
        if ($exchange === null
            || !in_array(
                $exchange->getReplacementStatus(),
                [
                    ReplacementStatus::ORDERED,
                    ReplacementStatus::SHIPPED,
                    ReplacementStatus::DELIVERED,
                ],
                true
            )
        ) {
            return false;
        }

        $documentLink = $this->getReplacementOrderLink();

        return $documentLink !== null
            && $documentLink->getDocumentType() === DocumentType::ORDER
            && $documentLink->getDocumentId() > 0;
    }

    public function canReject(): bool
    {
        $exchange = $this->getExchange();
        return $exchange !== null
            && $this->_authorization->isAllowed(self::ACL_CANCEL)
            && $exchange->getExchangeStatus() === ExchangeStatus::IN_PROGRESS
            && $exchange->getReturnStatus() === ReturnStatus::REJECTED;
    }

    public function canCancel(): bool
    {
        $exchange = $this->getExchange();
        if ($exchange === null
            || !$this->_authorization->isAllowed(self::ACL_CANCEL)
            || in_array($exchange->getExchangeStatus(), ExchangeStatus::terminal(), true)
        ) {
            return false;
        }

        return in_array(
            $exchange->getReturnStatus(),
            [
                ReturnStatus::PENDING,
                ReturnStatus::AUTHORIZED,
                ReturnStatus::IN_TRANSIT,
                ReturnStatus::CANCELLED,
            ],
            true
        ) && in_array(
            $exchange->getReplacementStatus(),
            [ReplacementStatus::PENDING, ReplacementStatus::CANCELLED],
            true
        ) && in_array(
            $exchange->getSettlementStatus(),
            [
                SettlementStatus::PENDING,
                SettlementStatus::PAYMENT_DUE,
                SettlementStatus::REFUND_DUE,
                SettlementStatus::FAILED,
                SettlementStatus::CANCELLED,
            ],
            true
        );
    }

    /**
     * @return array<string, Phrase>
     */
    public function getConditionOptions(): array
    {
        return [
            'unopened' => __('Unopened'),
            'like_new' => __('Like New'),
            'opened' => __('Opened'),
            'damaged' => __('Damaged'),
            'defective' => __('Defective'),
        ];
    }

    /**
     * @return array<string, Phrase>
     */
    public function getDispositionOptions(): array
    {
        return [
            'restock' => __('Return to Saleable Stock'),
            'quarantine' => __('Keep in Quarantine'),
            'write_off' => __('Write Off'),
            'return_to_vendor' => __('Return to Vendor'),
        ];
    }

    private function hasValidSettlementAmounts(
        ExchangeInterface $exchange
    ): bool {
        if ($this->quantityMath->compare($exchange->getFeeAmount(), '0') !== 0
            || $this->quantityMath->compare(
                $exchange->getNativeReturnCreditAmount(),
                '0'
            ) < 0
        ) {
            return false;
        }

        $replacementAmount = '0.0000';
        if ($exchange->getReplacementStatus() !== ReplacementStatus::CANCELLED) {
            if ($this->quantityMath->compare(
                $exchange->getNativeReplacementAmount(),
                '0'
            ) < 0 || $this->quantityMath->compare(
                $exchange->getBaseNativeReplacementAmount(),
                '0'
            ) < 0) {
                return false;
            }
            $replacementAmount = $exchange->getNativeReplacementAmount();
        }
        $derivedBalance = $this->quantityMath->subtract(
            $replacementAmount,
            $exchange->getNativeReturnCreditAmount()
        );

        return $this->quantityMath->compare(
            $derivedBalance,
            $exchange->getBalanceAmount()
        ) === 0;
    }

    private function hasFullyCreditedReturnItems(): bool
    {
        $returnItems = $this->getReturnItems();
        if ($returnItems === []) {
            return false;
        }
        foreach ($returnItems as $item) {
            if ($this->quantityMath->compare(
                $item->getAcceptedQty(),
                $item->getCreditedQty()
            ) !== 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param DocumentLinkInterface[] $documentLinks
     */
    private function hasCleanCancelledReplacement(
        ExchangeInterface $exchange,
        array $documentLinks
    ): bool {
        if ($this->quantityMath->compare(
            $exchange->getNativeReplacementAmount(),
            '0'
        ) !== 0 || $this->quantityMath->compare(
            $exchange->getBaseNativeReplacementAmount(),
            '0'
        ) !== 0) {
            return false;
        }
        foreach ($this->getReplacementItems() as $item) {
            if ($item->getReplacementOrderItemId() !== null) {
                return false;
            }
        }
        $orderLinks = [];
        foreach ($documentLinks as $documentLink) {
            $type = $documentLink->getDocumentType();
            if ($type === DocumentType::ORDER) {
                $orderLinks[] = $documentLink;
            } elseif (in_array(
                $type,
                [DocumentType::INVOICE, DocumentType::SHIPMENT],
                true
            )) {
                return false;
            }
        }
        if (count($orderLinks) > 1) {
            return false;
        }
        if ($orderLinks !== []) {
            $exchangeId = (int)$exchange->getEntityId();
            if ($exchangeId <= 0
                || $orderLinks[0]->getDocumentId() <= 0
                || $orderLinks[0]->getOperationKey()
                    !== $this->settlementOperationKeys
                        ->replacementOrder($exchangeId)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param DocumentLinkInterface[] $documentLinks
     */
    private function hasTrustedReplacementDocuments(
        ExchangeInterface $exchange,
        array $documentLinks
    ): bool {
        $replacementItems = $this->getReplacementItems();
        if ($replacementItems === []) {
            return false;
        }
        foreach ($replacementItems as $item) {
            if (($item->getReplacementOrderItemId() ?? 0) <= 0) {
                return false;
            }
        }

        $orderLinks = [];
        $invoiceLinks = [];
        foreach ($documentLinks as $documentLink) {
            if ($documentLink->getDocumentType() === DocumentType::ORDER) {
                $orderLinks[] = $documentLink;
            } elseif ($documentLink->getDocumentType() === DocumentType::INVOICE) {
                $invoiceLinks[] = $documentLink;
            }
        }
        $exchangeId = (int)$exchange->getEntityId();
        if ($exchangeId <= 0
            || count($orderLinks) !== 1
            || $orderLinks[0]->getDocumentId() <= 0
            || $orderLinks[0]->getOperationKey()
                !== $this->settlementOperationKeys->replacementOrder($exchangeId)
            || count($invoiceLinks) > 1
        ) {
            return false;
        }

        return $invoiceLinks === []
            || (
                $invoiceLinks[0]->getDocumentId() > 0
                && $invoiceLinks[0]->getOperationKey()
                    === $this->settlementOperationKeys->invoice($exchangeId)
            );
    }

    /**
     * @return DocumentLinkInterface[]
     */
    private function getSettlementDocumentLinks(): array
    {
        if ($this->settlementDocumentLinks !== null) {
            return $this->settlementDocumentLinks;
        }
        $exchange = $this->getExchange();
        if ($exchange === null || $exchange->getEntityId() === null) {
            return $this->settlementDocumentLinks = [];
        }

        /** @var SearchCriteriaBuilder $builder */
        $builder = $this->searchCriteriaBuilderFactory->create();
        $items = $this->documentLinkRepository->getList(
            $builder
                ->addFilter(
                    DocumentLinkInterface::EXCHANGE_ID,
                    (int)$exchange->getEntityId()
                )
                ->setPageSize(500)
                ->create()
        )->getItems();

        return $this->settlementDocumentLinks = array_values($items);
    }

    private function createExchangeItemCriteria(int $exchangeId): \Magento\Framework\Api\SearchCriteriaInterface
    {
        /** @var SearchCriteriaBuilder $builder */
        $builder = $this->searchCriteriaBuilderFactory->create();
        return $builder
            ->addFilter('exchange_id', $exchangeId)
            ->setPageSize(500)
            ->create();
    }

    private function addRedirectButton(
        string $id,
        string $label,
        string $url,
        string $cssClass,
        int $sortOrder
    ): void {
        $this->addButton(
            $id,
            [
                'label' => $label,
                'class' => $cssClass,
                'data_attribute' => [
                    'mage-init' => [
                        'Bonlineco_SalesExchange/js/action-post' => [
                            'url' => $url,
                            'post' => false,
                        ],
                    ],
                ],
            ],
            0,
            $sortOrder
        );
    }

    private function addPostButton(
        string $id,
        string $label,
        string $route,
        string $confirmTitle,
        string $confirmMessage,
        string $cssClass,
        int $sortOrder
    ): void {
        $exchange = $this->getExchange();
        if ($exchange === null || $exchange->getEntityId() === null) {
            return;
        }

        $this->addButton(
            $id,
            [
                'label' => $label,
                'class' => $cssClass,
                'data_attribute' => [
                    'mage-init' => [
                        'Bonlineco_SalesExchange/js/action-post' => [
                            'url' => $this->getUrl($route),
                            'post' => true,
                            'params' => [
                                'form_key' => $this->getFormKey(),
                                'entity_id' => $exchange->getEntityId(),
                                'version' => $exchange->getVersion(),
                            ],
                            'confirm' => [
                                'title' => $confirmTitle,
                                'content' => $confirmMessage,
                            ],
                        ],
                    ],
                ],
            ],
            0,
            $sortOrder
        );
    }
}
