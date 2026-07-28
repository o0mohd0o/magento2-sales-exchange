<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model;

use Bonlineco\SalesExchange\Api\Data\HistoryInterface;
use Bonlineco\SalesExchange\Api\Data\HistorySearchResultsInterface;
use Bonlineco\SalesExchange\Api\HistoryRepositoryInterface;
use Bonlineco\SalesExchange\Model\ResourceModel\History as HistoryResource;
use Bonlineco\SalesExchange\Model\ResourceModel\History\CollectionFactory;
use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;

/**
 * Read-only repository for append-only exchange audit history.
 */
class HistoryRepository extends AbstractRepository implements HistoryRepositoryInterface
{
    private HistoryFactory $historyFactory;

    private HistoryResource $historyResource;

    private CollectionFactory $collectionFactory;

    private HistorySearchResultsFactory $searchResultsFactory;

    private CollectionProcessorInterface $collectionProcessor;

    public function __construct(
        HistoryFactory $historyFactory,
        HistoryResource $historyResource,
        CollectionFactory $collectionFactory,
        HistorySearchResultsFactory $searchResultsFactory,
        CollectionProcessorInterface $collectionProcessor
    ) {
        $this->historyFactory = $historyFactory;
        $this->historyResource = $historyResource;
        $this->collectionFactory = $collectionFactory;
        $this->searchResultsFactory = $searchResultsFactory;
        $this->collectionProcessor = $collectionProcessor;
    }

    /**
     * @inheritdoc
     */
    public function getById(int $historyId): HistoryInterface
    {
        $history = $this->historyFactory->create();
        /** @var HistoryInterface $loaded */
        $loaded = $this->requireLoaded(
            $history,
            $this->historyResource,
            $historyId,
            HistoryInterface::ENTITY_ID,
            'exchange history record'
        );

        return $loaded;
    }

    /**
     * @inheritdoc
     */
    public function getList(SearchCriteriaInterface $searchCriteria): HistorySearchResultsInterface
    {
        $results = $this->searchResultsFactory->create();
        /** @var HistorySearchResultsInterface $results */
        return $this->buildSearchResults(
            $this->collectionFactory->create(),
            $searchCriteria,
            $results,
            $this->collectionProcessor
        );
    }
}
