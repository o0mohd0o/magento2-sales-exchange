<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Controller\Adminhtml\Exchange;

use Bonlineco\SalesExchange\Api\ExchangeEligibilityInterface;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\PageFactory;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Draft exchange creation form.
 */
class NewAction extends CreationAction
{
    private PageFactory $pageFactory;

    private ExchangeEligibilityInterface $exchangeEligibility;

    private Registry $registry;

    private OrderRepositoryInterface $orderRepository;

    private SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory;

    public function __construct(
        Context $context,
        PageFactory $pageFactory,
        ExchangeEligibilityInterface $exchangeEligibility,
        Registry $registry,
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory
    ) {
        parent::__construct($context);
        $this->pageFactory = $pageFactory;
        $this->exchangeEligibility = $exchangeEligibility;
        $this->registry = $registry;
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilderFactory = $searchCriteriaBuilderFactory;
    }

    public function execute(): ResultInterface
    {
        $orderId = (int)$this->getRequest()->getParam('order_id');
        try {
            if ($orderId <= 0) {
                $orderId = $this->resolveOrderIdByIncrementId(
                    trim((string)$this->getRequest()->getParam('order_increment_id'))
                );
            }
            if ($orderId <= 0) {
                $page = $this->pageFactory->create();
                $page->setActiveMenu('Bonlineco_SalesExchange::exchange_orders');
                $page->getConfig()->getTitle()->prepend(__('Create Exchange'));

                return $page;
            }
            $order = $this->exchangeEligibility->execute($orderId);
            $this->registry->register('bonlineco_sales_exchange_order', $order);
            $page = $this->pageFactory->create();
            $page->setActiveMenu('Bonlineco_SalesExchange::exchange_orders');
            $page->getConfig()->getTitle()->prepend(
                __('Create Exchange for Order #%1', $order->getIncrementId())
            );

            return $page;
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
            /** @var Redirect $redirect */
            $redirect = $this->resultRedirectFactory->create();
            if ($orderId > 0) {
                return $redirect->setPath('sales/order/view', ['order_id' => $orderId]);
            }

            return $redirect->setPath('sales/order/index');
        }
    }

    private function resolveOrderIdByIncrementId(string $incrementId): int
    {
        if ($incrementId === '') {
            return 0;
        }
        $builder = $this->searchCriteriaBuilderFactory->create();
        $orders = $this->orderRepository->getList(
            $builder
                ->addFilter(OrderInterface::INCREMENT_ID, $incrementId)
                ->setPageSize(1)
                ->create()
        )->getItems();
        if ($orders === []) {
            throw new LocalizedException(
                __('No order with number "%1" exists.', $incrementId)
            );
        }

        return (int)reset($orders)->getEntityId();
    }
}
