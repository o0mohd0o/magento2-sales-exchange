<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model;

use Bonlineco\SalesExchange\Api\Data\ReplacementItemInterface;
use Bonlineco\SalesExchange\Api\Data\ReplacementItemSearchResultsInterface;
use Bonlineco\SalesExchange\Api\ReplacementItemRepositoryInterface;
use Bonlineco\SalesExchange\Api\Status\ExchangeStatus;
use Bonlineco\SalesExchange\Api\Status\ReplacementStatus;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Bonlineco\SalesExchange\Model\ResourceModel\Exchange as ExchangeResource;
use Bonlineco\SalesExchange\Model\ResourceModel\ReplacementItem as ReplacementItemResource;
use Bonlineco\SalesExchange\Model\ResourceModel\ReplacementItem\CollectionFactory;
use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Replacement line repository with transactional case-state validation.
 */
class ReplacementItemRepository extends AbstractRepository implements ReplacementItemRepositoryInterface
{
    private ReplacementItemFactory $replacementItemFactory;

    private ReplacementItemResource $replacementItemResource;

    private CollectionFactory $collectionFactory;

    private ReplacementItemSearchResultsFactory $searchResultsFactory;

    private CollectionProcessorInterface $collectionProcessor;

    private ExchangeResource $exchangeResource;

    private DecimalMath $moneyMath;

    private DecimalMath $quantityMath;

    private ReplacementCurrencyCalculator $replacementCurrencyCalculator;

    private VersionGuard $versionGuard;

    private AggregateVersionBumper $aggregateVersionBumper;

    public function __construct(
        ReplacementItemFactory $replacementItemFactory,
        ReplacementItemResource $replacementItemResource,
        CollectionFactory $collectionFactory,
        ReplacementItemSearchResultsFactory $searchResultsFactory,
        CollectionProcessorInterface $collectionProcessor,
        ExchangeResource $exchangeResource,
        DecimalMath $moneyMath,
        DecimalMath $quantityMath,
        ReplacementCurrencyCalculator $replacementCurrencyCalculator,
        VersionGuard $versionGuard,
        AggregateVersionBumper $aggregateVersionBumper
    ) {
        $this->replacementItemFactory = $replacementItemFactory;
        $this->replacementItemResource = $replacementItemResource;
        $this->collectionFactory = $collectionFactory;
        $this->searchResultsFactory = $searchResultsFactory;
        $this->collectionProcessor = $collectionProcessor;
        $this->exchangeResource = $exchangeResource;
        $this->moneyMath = $moneyMath;
        $this->quantityMath = $quantityMath;
        $this->replacementCurrencyCalculator = $replacementCurrencyCalculator;
        $this->versionGuard = $versionGuard;
        $this->aggregateVersionBumper = $aggregateVersionBumper;
    }

    /**
     * @inheritdoc
     */
    public function save(ReplacementItemInterface $replacementItem): ReplacementItemInterface
    {
        if (!$replacementItem instanceof ReplacementItem) {
            throw new CouldNotSaveException(__('The replacement item data implementation is not supported.'));
        }

        $isNew = $replacementItem->getEntityId() === null;
        if ($isNew) {
            $replacementItem->setVersion(VersionGuard::INITIAL_VERSION);
            $replacementItem->unsetData(ReplacementItemInterface::CREATED_AT);
            $replacementItem->unsetData(ReplacementItemInterface::UPDATED_AT);
        }
        if ($replacementItem->getExchangeId() <= 0) {
            throw new InvariantViolationException(__('An exchange case is required.'));
        }
        $connection = $this->replacementItemResource->getConnection();
        $connection->beginTransaction();
        try {
            $exchange = $this->exchangeResource->getDataForUpdate($replacementItem->getExchangeId());
            if ($exchange === null) {
                throw new NoSuchEntityException(
                    __('No exchange case exists for ID "%1".', $replacementItem->getExchangeId())
                );
            }
            $this->assertCaseIsWritable($exchange);
            if ($isNew && (string)$exchange['replacement_status'] !== ReplacementStatus::PENDING) {
                throw new InvariantViolationException(
                    __('Replacement items can only be added while replacement work is pending.')
                );
            }
            $this->validate($replacementItem);
            $this->assertIdentityWasNotChanged($replacementItem);
            /** @var ReplacementItemInterface $saved */
            $saved = $this->persist(
                $replacementItem,
                $this->replacementItemResource,
                'replacement item'
            );
            $this->aggregateVersionBumper->execute(
                $replacementItem->getExchangeId(),
                (int)$exchange['version']
            );
            $connection->commit();

            return $saved;
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }
    }

    /**
     * @inheritdoc
     */
    public function getById(int $replacementItemId): ReplacementItemInterface
    {
        $replacementItem = $this->replacementItemFactory->create();
        /** @var ReplacementItemInterface $loaded */
        $loaded = $this->requireLoaded(
            $replacementItem,
            $this->replacementItemResource,
            $replacementItemId,
            ReplacementItemInterface::ENTITY_ID,
            'replacement item'
        );

        return $loaded;
    }

    /**
     * @inheritdoc
     */
    public function getList(
        SearchCriteriaInterface $searchCriteria
    ): ReplacementItemSearchResultsInterface {
        $results = $this->searchResultsFactory->create();
        /** @var ReplacementItemSearchResultsInterface $results */
        return $this->buildSearchResults(
            $this->collectionFactory->create(),
            $searchCriteria,
            $results,
            $this->collectionProcessor
        );
    }

    private function validate(ReplacementItemInterface $replacementItem): void
    {
        if (trim($replacementItem->getSku()) === '' || trim($replacementItem->getName()) === '') {
            throw new InvariantViolationException(__('Replacement SKU and name are required.'));
        }

        $quantity = $this->quantityMath->assertNonNegative(
            $replacementItem->getQty(),
            'Replacement quantity'
        );
        if ($this->quantityMath->compare($quantity, '0') <= 0) {
            throw new InvariantViolationException(__('Replacement quantity must be greater than zero.'));
        }
        $replacementItem->setQty($quantity);
        $replacementItem->setUnitPriceAmount(
            $this->replacementCurrencyCalculator->normalizeUnit(
                $replacementItem->getUnitPriceAmount()
            )
        );
        $incomingRowTotal = $this->moneyMath->assertNonNegative(
            $replacementItem->getRowTotalAmount(),
            'Replacement row total'
        );
        $calculatedRowTotal = $this->replacementCurrencyCalculator->execute(
            $replacementItem->getQty(),
            $replacementItem->getUnitPriceAmount()
        );
        if ($this->moneyMath->compare($incomingRowTotal, $calculatedRowTotal) !== 0) {
            throw new InvariantViolationException(
                __('Replacement row total must equal quantity multiplied by unit price.')
            );
        }
        $replacementItem->setRowTotalAmount($calculatedRowTotal);
    }

    /**
     * @param array<string, mixed> $exchange
     */
    private function assertCaseIsWritable(array $exchange): void
    {
        if (in_array(
            (string)$exchange['exchange_status'],
            ExchangeStatus::terminal(),
            true
        ) || in_array(
            (string)$exchange['replacement_status'],
            ReplacementStatus::terminal(),
            true
        )) {
            throw new InvariantViolationException(
                __('Replacement items cannot be changed after their workflow is closed.')
            );
        }
    }

    private function assertIdentityWasNotChanged(ReplacementItem $replacementItem): void
    {
        if ($replacementItem->getEntityId() === null) {
            $this->assertReplacementOrderItemLinkWasNotChanged(
                null,
                $replacementItem->getReplacementOrderItemId()
            );

            return;
        }

        $persisted = $this->replacementItemResource->getDataForUpdate(
            $replacementItem->getEntityId()
        );
        if ($persisted === null) {
            throw new NoSuchEntityException(
                __('No replacement item exists for ID "%1".', $replacementItem->getEntityId())
            );
        }
        if ((int)$persisted[ReplacementItemInterface::EXCHANGE_ID] !== $replacementItem->getExchangeId()) {
            throw new InvariantViolationException(
                __('A replacement item cannot be moved to another exchange case.')
            );
        }
        $this->assertReplacementOrderItemLinkWasNotChanged(
            $this->nullableInt(
                $persisted[ReplacementItemInterface::REPLACEMENT_ORDER_ITEM_ID]
            ),
            $replacementItem->getReplacementOrderItemId()
        );
        $replacementItem->setVersion(
            $this->versionGuard->assertCurrentAndIncrement(
                $replacementItem->getVersion(),
                (int)$persisted[ReplacementItemInterface::VERSION],
                'replacement item'
            )
        );
        $replacementItem->setCreatedAt(
            (string)$persisted[ReplacementItemInterface::CREATED_AT]
        );
        $replacementItem->unsetData(ReplacementItemInterface::UPDATED_AT);
        $this->assertCanonicalSnapshot($replacementItem, $persisted);
    }

    /**
     * Keep native fulfillment assignment inside the locked replacement-order workflow.
     */
    private function assertReplacementOrderItemLinkWasNotChanged(
        ?int $persistedLink,
        ?int $incomingLink
    ): void {
        if ($persistedLink !== $incomingLink) {
            throw new InvariantViolationException(
                __('A replacement order item link can only be changed by the replacement-order workflow.')
            );
        }
    }

    /**
     * @param array<string, mixed> $persisted
     */
    private function assertCanonicalSnapshot(
        ReplacementItemInterface $replacementItem,
        array $persisted
    ): void {
        $sameIntent = $this->nullableInt($persisted[ReplacementItemInterface::PRODUCT_ID])
                === $replacementItem->getProductId()
            && (string)$persisted[ReplacementItemInterface::SKU] === $replacementItem->getSku()
            && (string)$persisted[ReplacementItemInterface::NAME] === $replacementItem->getName()
            && $this->quantityMath->compare(
                (string)$persisted[ReplacementItemInterface::QTY],
                $replacementItem->getQty()
            ) === 0
            && $this->moneyMath->compare(
                (string)$persisted[ReplacementItemInterface::UNIT_PRICE_AMOUNT],
                $replacementItem->getUnitPriceAmount()
            ) === 0
            && $this->moneyMath->compare(
                (string)$persisted[ReplacementItemInterface::ROW_TOTAL_AMOUNT],
                $replacementItem->getRowTotalAmount()
            ) === 0
            && (string)$persisted[ReplacementItemInterface::PRODUCT_OPTIONS_JSON]
                === (string)$replacementItem->getProductOptionsJson();
        if (!$sameIntent) {
            throw new InvariantViolationException(
                __('A replacement item catalog and financial snapshot is immutable.')
            );
        }
    }

    /**
     * @param mixed $value
     */
    private function nullableInt($value): ?int
    {
        return $value === null ? null : (int)$value;
    }
}
