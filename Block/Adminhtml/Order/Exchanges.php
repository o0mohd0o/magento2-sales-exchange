<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Block\Adminhtml\Order;

use Bonlineco\SalesExchange\Api\ConfigInterface;
use Bonlineco\SalesExchange\Api\Data\ExchangeInterface;
use Bonlineco\SalesExchange\Api\ExchangeEligibilityInterface;
use Bonlineco\SalesExchange\Api\ExchangeRepositoryInterface;
use Bonlineco\SalesExchange\Model\Workflow\AdminActionMap;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Framework\Exception\LocalizedException;

/**
 * Link an original sales order to its exchange cases.
 */
class Exchanges extends Template
{
    private const ACL_VIEW = 'Bonlineco_SalesExchange::view';
    private const ACL_CREATE = 'Bonlineco_SalesExchange::create';

    private ExchangeRepositoryInterface $exchangeRepository;

    private SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory;

    private ConfigInterface $config;

    private ExchangeEligibilityInterface $exchangeEligibility;

    /**
     * @var ExchangeInterface[]|null
     */
    private ?array $exchanges = null;

    /**
     * @param Context $context
     * @param ExchangeRepositoryInterface $exchangeRepository
     * @param SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory
     * @param ConfigInterface $config
     * @param ExchangeEligibilityInterface $exchangeEligibility
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        ExchangeRepositoryInterface $exchangeRepository,
        SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory,
        ConfigInterface $config,
        ExchangeEligibilityInterface $exchangeEligibility,
        array $data = []
    ) {
        $this->exchangeRepository = $exchangeRepository;
        $this->searchCriteriaBuilderFactory = $searchCriteriaBuilderFactory;
        $this->config = $config;
        $this->exchangeEligibility = $exchangeEligibility;
        parent::__construct($context, $data);
    }

    /**
     * @return ExchangeInterface[]
     */
    public function getExchanges(): array
    {
        if ($this->exchanges !== null) {
            return $this->exchanges;
        }

        $orderId = (int)$this->getRequest()->getParam('order_id');
        if ($orderId <= 0 || !$this->_authorization->isAllowed(self::ACL_VIEW)) {
            return $this->exchanges = [];
        }

        /** @var SearchCriteriaBuilder $builder */
        $builder = $this->searchCriteriaBuilderFactory->create();
        $items = $this->exchangeRepository->getList(
            $builder
                ->addFilter(ExchangeInterface::ORIGINAL_ORDER_ID, $orderId)
                ->setPageSize(100)
                ->create()
        )->getItems();
        usort(
            $items,
            static fn (ExchangeInterface $left, ExchangeInterface $right): int =>
                strcmp($right->getCreatedAt() ?? '', $left->getCreatedAt() ?? '')
        );

        return $this->exchanges = $items;
    }

    public function canCreate(): bool
    {
        $orderId = (int)$this->getRequest()->getParam('order_id');
        if ($orderId <= 0
            || !$this->_authorization->isAllowed(self::ACL_CREATE)
            || !$this->_authorization->isAllowed(AdminActionMap::ACL_SALES_ORDER_VIEW)
        ) {
            return false;
        }

        try {
            $order = $this->exchangeEligibility->execute($orderId);
            $storeId = $order->getStoreId() === null ? null : (int)$order->getStoreId();

            return $this->config->isEnabled($storeId);
        } catch (LocalizedException $exception) {
            return false;
        }
    }

    public function getCreateUrl(): string
    {
        return $this->getUrl(
            'salesexchange/exchange/new',
            ['order_id' => (int)$this->getRequest()->getParam('order_id')]
        );
    }

    public function getViewUrl(ExchangeInterface $exchange): string
    {
        return $this->getUrl(
            'salesexchange/exchange/view',
            ['entity_id' => (int)$exchange->getEntityId()]
        );
    }

    public function getStatusLabel(string $status): \Magento\Framework\Phrase
    {
        return match ($status) {
            'draft' => __('Draft'),
            'pending_approval' => __('Pending Approval'),
            'approved' => __('Approved'),
            'in_progress' => __('In Progress'),
            'completed' => __('Completed'),
            'rejected' => __('Rejected'),
            'cancelled' => __('Cancelled'),
            default => __(ucwords(str_replace('_', ' ', $status))),
        };
    }
}
