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
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;

/**
 * Locate the one isolated quote reserved for an exchange intent.
 */
class PreparedQuoteLookup
{
    private CartRepositoryInterface $quoteRepository;

    private SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory;

    public function __construct(
        CartRepositoryInterface $quoteRepository,
        SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory
    ) {
        $this->quoteRepository = $quoteRepository;
        $this->searchCriteriaBuilderFactory = $searchCriteriaBuilderFactory;
    }

    public function find(int $exchangeId, string $intentHash): ?Quote
    {
        $this->assertIdentity($exchangeId, $intentHash);
        /** @var SearchCriteriaBuilder $builder */
        $builder = $this->searchCriteriaBuilderFactory->create();
        $items = $this->quoteRepository->getList(
            $builder->addFilter(Marker::EXCHANGE_ID, $exchangeId)
                ->setPageSize(2)
                ->create()
        )->getItems();
        if (count($items) > 1) {
            throw new InvariantViolationException(
                __('More than one native quote is marked for this exchange.')
            );
        }
        $quote = reset($items);
        if ($quote === false) {
            return null;
        }
        if (!$quote instanceof Quote) {
            throw new InvariantViolationException(
                __('The native quote implementation is not supported.')
            );
        }
        if ((int)$quote->getData(Marker::EXCHANGE_ID) !== $exchangeId
            || !is_string($quote->getData(Marker::INTENT_HASH))
            || !hash_equals(
                $intentHash,
                (string)$quote->getData(Marker::INTENT_HASH)
            )
        ) {
            throw new InvariantViolationException(
                __('The existing native quote belongs to a different replacement intent.')
            );
        }

        return $quote;
    }

    private function assertIdentity(int $exchangeId, string $intentHash): void
    {
        if ($exchangeId <= 0 || !preg_match('/^[a-f0-9]{64}$/D', $intentHash)) {
            throw new InvariantViolationException(
                __('The replacement quote lookup identity is invalid.')
            );
        }
    }
}
