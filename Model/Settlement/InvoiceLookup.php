<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\Settlement;

use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Sales\Api\Data\InvoiceInterface;
use Magento\Sales\Api\InvoiceRepositoryInterface;

/**
 * Locate the sole native invoice for a replacement order.
 */
class InvoiceLookup
{
    private InvoiceRepositoryInterface $invoiceRepository;

    private SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory;

    public function __construct(
        InvoiceRepositoryInterface $invoiceRepository,
        SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory
    ) {
        $this->invoiceRepository = $invoiceRepository;
        $this->searchCriteriaBuilderFactory = $searchCriteriaBuilderFactory;
    }

    public function find(int $orderId): ?InvoiceInterface
    {
        if ($orderId <= 0) {
            throw new InvariantViolationException(
                __('A valid replacement order is required for invoice lookup.')
            );
        }
        /** @var SearchCriteriaBuilder $builder */
        $builder = $this->searchCriteriaBuilderFactory->create();
        $items = $this->invoiceRepository->getList(
            $builder->addFilter(InvoiceInterface::ORDER_ID, $orderId)
                ->setPageSize(2)
                ->create()
        )->getItems();
        if (count($items) > 1) {
            throw new InvariantViolationException(
                __('The replacement order has more than one native invoice.')
            );
        }
        $invoice = reset($items);
        if ($invoice === false) {
            return null;
        }
        if (!$invoice instanceof InvoiceInterface
            || (int)$invoice->getEntityId() <= 0
        ) {
            throw new InvariantViolationException(
                __('The native replacement invoice is invalid.')
            );
        }

        return $this->invoiceRepository->get((int)$invoice->getEntityId());
    }
}
