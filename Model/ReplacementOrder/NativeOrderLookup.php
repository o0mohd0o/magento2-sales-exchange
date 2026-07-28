<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\ReplacementOrder;

use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Framework\Model\AbstractModel;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Locate the one native order already committed for an exchange intent.
 */
class NativeOrderLookup
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

    public function find(int $exchangeId, string $intentHash): ?OrderInterface
    {
        if ($exchangeId <= 0 || !preg_match('/^[a-f0-9]{64}$/D', $intentHash)) {
            throw new InvariantViolationException(
                __('The replacement order lookup identity is invalid.')
            );
        }
        /** @var SearchCriteriaBuilder $builder */
        $builder = $this->searchCriteriaBuilderFactory->create();
        $items = $this->orderRepository->getList(
            $builder->addFilter(Marker::EXCHANGE_ID, $exchangeId)
                ->setPageSize(2)
                ->create()
        )->getItems();
        if (count($items) > 1) {
            throw new InvariantViolationException(
                __('More than one native order is marked for this exchange.')
            );
        }
        $order = reset($items);
        if ($order === false) {
            return null;
        }
        if (!$order instanceof OrderInterface || !$order instanceof AbstractModel) {
            throw new InvariantViolationException(
                __('The native order implementation is not supported.')
            );
        }
        $persistedHash = $order->getData(Marker::INTENT_HASH);
        if ((int)$order->getData(Marker::EXCHANGE_ID) !== $exchangeId
            || !is_string($persistedHash)
            || !hash_equals($intentHash, $persistedHash)
        ) {
            throw new InvariantViolationException(
                __('The existing native order belongs to a different replacement intent.')
            );
        }
        if ((int)$order->getEntityId() <= 0) {
            throw new InvariantViolationException(
                __('The marked native replacement order is not persisted.')
            );
        }

        return $order;
    }
}
