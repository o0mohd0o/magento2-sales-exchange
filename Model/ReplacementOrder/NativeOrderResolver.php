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
 * Resolve a committed replacement order by markers and by prepared quote ID.
 *
 * The quote lookup is the last duplicate-prevention barrier when another
 * extension unexpectedly strips or changes the order markers.
 */
class NativeOrderResolver
{
    private NativeOrderLookup $nativeOrderLookup;

    private OrderRepositoryInterface $orderRepository;

    private SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory;

    public function __construct(
        NativeOrderLookup $nativeOrderLookup,
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory
    ) {
        $this->nativeOrderLookup = $nativeOrderLookup;
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilderFactory = $searchCriteriaBuilderFactory;
    }

    public function find(
        int $exchangeId,
        string $intentHash,
        ?int $quoteId = null
    ): ?OrderInterface {
        $marked = $this->nativeOrderLookup->find($exchangeId, $intentHash);
        if ($quoteId === null) {
            return $marked;
        }
        if ($quoteId <= 0) {
            throw new InvariantViolationException(
                __('The prepared replacement quote ID is invalid.')
            );
        }

        /** @var SearchCriteriaBuilder $builder */
        $builder = $this->searchCriteriaBuilderFactory->create();
        $items = $this->orderRepository->getList(
            $builder->addFilter(OrderInterface::QUOTE_ID, $quoteId)
                ->setPageSize(2)
                ->create()
        )->getItems();
        if (count($items) > 1) {
            throw new InvariantViolationException(
                __('More than one native order was created from the replacement quote.')
            );
        }
        $quoteOrder = reset($items);
        if ($quoteOrder === false) {
            return $marked;
        }
        if (!$quoteOrder instanceof OrderInterface
            || !$quoteOrder instanceof AbstractModel
            || (int)$quoteOrder->getEntityId() <= 0
        ) {
            throw new InvariantViolationException(
                __('The native order created from the replacement quote is invalid.')
            );
        }

        $persistedHash = $quoteOrder->getData(Marker::INTENT_HASH);
        if ((int)$quoteOrder->getData(Marker::EXCHANGE_ID) !== $exchangeId
            || !is_string($persistedHash)
            || !hash_equals($intentHash, $persistedHash)
        ) {
            throw new InvariantViolationException(
                __(
                    'A native order exists for the prepared quote without '
                    . 'the exact trusted replacement markers.'
                )
            );
        }
        if ($marked !== null
            && (int)$marked->getEntityId() !== (int)$quoteOrder->getEntityId()
        ) {
            throw new InvariantViolationException(
                __('The replacement quote and exchange markers resolve to different native orders.')
            );
        }

        return $quoteOrder;
    }
}
