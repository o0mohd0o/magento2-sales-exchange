<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model;

use Bonlineco\SalesExchange\Api\Data\DocumentLinkInterface;
use Bonlineco\SalesExchange\Api\Data\DocumentLinkSearchResultsInterface;
use Bonlineco\SalesExchange\Api\DocumentLinkRepositoryInterface;
use Bonlineco\SalesExchange\Model\ResourceModel\DocumentLink as DocumentLinkResource;
use Bonlineco\SalesExchange\Model\ResourceModel\DocumentLink\CollectionFactory;
use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Read-only repository for native-document audit links.
 */
class DocumentLinkRepository extends AbstractRepository implements DocumentLinkRepositoryInterface
{
    private DocumentLinkFactory $documentLinkFactory;

    private DocumentLinkResource $documentLinkResource;

    private CollectionFactory $collectionFactory;

    private DocumentLinkSearchResultsFactory $searchResultsFactory;

    private CollectionProcessorInterface $collectionProcessor;

    public function __construct(
        DocumentLinkFactory $documentLinkFactory,
        DocumentLinkResource $documentLinkResource,
        CollectionFactory $collectionFactory,
        DocumentLinkSearchResultsFactory $searchResultsFactory,
        CollectionProcessorInterface $collectionProcessor
    ) {
        $this->documentLinkFactory = $documentLinkFactory;
        $this->documentLinkResource = $documentLinkResource;
        $this->collectionFactory = $collectionFactory;
        $this->searchResultsFactory = $searchResultsFactory;
        $this->collectionProcessor = $collectionProcessor;
    }

    public function getById(int $documentLinkId): DocumentLinkInterface
    {
        $documentLink = $this->documentLinkFactory->create();
        /** @var DocumentLinkInterface $loaded */
        $loaded = $this->requireLoaded(
            $documentLink,
            $this->documentLinkResource,
            $documentLinkId,
            DocumentLinkInterface::ENTITY_ID,
            'native document link'
        );

        return $loaded;
    }

    public function getByOperationKey(string $operationKey): DocumentLinkInterface
    {
        $documentLink = $this->findByOperationKey($operationKey);
        if ($documentLink === null) {
            throw new NoSuchEntityException(
                __('No native document link exists for operation key "%1".', $operationKey)
            );
        }

        return $documentLink;
    }

    public function getList(SearchCriteriaInterface $searchCriteria): DocumentLinkSearchResultsInterface
    {
        $results = $this->searchResultsFactory->create();
        /** @var DocumentLinkSearchResultsInterface $results */
        return $this->buildSearchResults(
            $this->collectionFactory->create(),
            $searchCriteria,
            $results,
            $this->collectionProcessor
        );
    }

    private function findByOperationKey(string $operationKey): ?DocumentLinkInterface
    {
        $documentLink = $this->documentLinkFactory->create();
        $this->documentLinkResource->load(
            $documentLink,
            $operationKey,
            DocumentLinkInterface::OPERATION_KEY
        );

        return $documentLink->getEntityId() === null ? null : $documentLink;
    }

}
