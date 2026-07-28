<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\Creditmemo;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Load a preview order without priming OrderRepository's identity map.
 */
class PreviewOrderLoader
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
        /** @var SearchCriteriaBuilder $builder */
        $builder = $this->searchCriteriaBuilderFactory->create();
        $items = $this->orderRepository->getList(
            $builder->addFilter('entity_id', $orderId)
                ->setPageSize(1)
                ->create()
        )->getItems();
        $order = reset($items);
        if (!$order instanceof OrderInterface || (int)$order->getEntityId() !== $orderId) {
            throw new NoSuchEntityException(__('The exchange original order no longer exists.'));
        }

        return $order;
    }
}
