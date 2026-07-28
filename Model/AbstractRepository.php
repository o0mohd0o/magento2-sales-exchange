<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model;

use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * Shared repository mechanics for module-owned flat entities.
 *
 * @SuppressWarnings(PHPMD.NumberOfChildren)
 */
abstract class AbstractRepository
{
    /**
     * @throws CouldNotSaveException
     */
    protected function persist(AbstractModel $model, AbstractDb $resource, string $entityLabel): AbstractModel
    {
        try {
            $resource->save($model);
        } catch (\Exception $exception) {
            throw new CouldNotSaveException(
                __('The %1 could not be saved.', $entityLabel),
                $exception
            );
        }

        return $model;
    }

    /**
     * @throws NoSuchEntityException
     */
    protected function requireLoaded(
        AbstractModel $model,
        AbstractDb $resource,
        int|string $value,
        string $field,
        string $entityLabel
    ): AbstractModel {
        $resource->load($model, $value, $field);
        if (!$model->getId()) {
            throw new NoSuchEntityException(
                __('No %1 exists for %2 "%3".', $entityLabel, $field, $value)
            );
        }

        return $model;
    }

    protected function buildSearchResults(
        AbstractCollection $collection,
        SearchCriteriaInterface $searchCriteria,
        SearchResultsInterface $searchResults,
        CollectionProcessorInterface $collectionProcessor
    ): SearchResultsInterface {
        $collectionProcessor->process($searchCriteria, $collection);
        $searchResults->setSearchCriteria($searchCriteria);
        $searchResults->setItems($collection->getItems());
        $searchResults->setTotalCount((int)$collection->getSize());

        return $searchResults;
    }
}
