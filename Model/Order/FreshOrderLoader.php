<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\Order;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Load an order through getList without using OrderRepository's identity map.
 */
class FreshOrderLoader
{
    private OrderRepositoryInterface $orderRepository;

    private SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory
    ) {
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilderFactory = $searchCriteriaBuilderFactory;
    }

    public function execute(int $orderId): OrderInterface
    {
        if ($orderId <= 0) {
            throw new NoSuchEntityException(__('The original order does not exist.'));
        }

        /** @var SearchCriteriaBuilder $builder */
        $builder = $this->searchCriteriaBuilderFactory->create();
        $orders = $this->orderRepository->getList(
            $builder->addFilter('entity_id', $orderId)
                ->setPageSize(1)
                ->create()
        )->getItems();
        $order = reset($orders);
        if (!$order instanceof OrderInterface || (int)$order->getEntityId() !== $orderId) {
            throw new NoSuchEntityException(
                __('The original order with ID "%1" does not exist.', $orderId)
            );
        }

        return $order;
    }
}
