<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model;

use Bonlineco\SalesExchange\Api\AllocationValidatorInterface;
use Bonlineco\SalesExchange\Api\Data\ReturnItemInterface;
use Bonlineco\SalesExchange\Api\Data\ReturnItemSearchResultsInterface;
use Bonlineco\SalesExchange\Api\FinancialRowCalculatorInterface;
use Bonlineco\SalesExchange\Api\RemainingQuantityCalculatorInterface;
use Bonlineco\SalesExchange\Api\ReturnItemRepositoryInterface;
use Bonlineco\SalesExchange\Api\Status\ExchangeStatus;
use Bonlineco\SalesExchange\Api\Status\ReturnStatus;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Bonlineco\SalesExchange\Model\Order\FreshOrderLoader;
use Bonlineco\SalesExchange\Model\ResourceModel\AllocationGuard;
use Bonlineco\SalesExchange\Model\ResourceModel\Exchange as ExchangeResource;
use Bonlineco\SalesExchange\Model\ResourceModel\ReturnItem as ReturnItemResource;
use Bonlineco\SalesExchange\Model\ResourceModel\ReturnItem\CollectionFactory;
use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Model\OrderMutexInterface;

/**
 * Return line repository with race-safe order-line allocation.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ReturnItemRepository extends AbstractRepository implements ReturnItemRepositoryInterface
{
    private ReturnItemFactory $returnItemFactory;

    private ReturnItemResource $returnItemResource;

    private CollectionFactory $collectionFactory;

    private ReturnItemSearchResultsFactory $searchResultsFactory;

    private CollectionProcessorInterface $collectionProcessor;

    private ExchangeResource $exchangeResource;

    private AllocationGuard $allocationGuard;

    private FreshOrderLoader $freshOrderLoader;

    private RemainingQuantityCalculatorInterface $remainingQuantityCalculator;

    private AllocationValidatorInterface $allocationValidator;

    private ReturnItemQuantityValidator $quantityValidator;

    private ReturnableOrderItemValidator $returnableOrderItemValidator;

    private DecimalMath $moneyMath;

    private DecimalMath $quantityMath;

    private FinancialRowCalculatorInterface $financialRowCalculator;

    private ReturnItemLifecycleValidator $lifecycleValidator;

    private VersionGuard $versionGuard;

    private AggregateVersionBumper $aggregateVersionBumper;

    private ExchangeFactory $exchangeFactory;

    private OrderMutexInterface $orderMutex;

    private CanonicalRefundedQuantity $canonicalRefundedQuantity;

    public function __construct(
        ReturnItemFactory $returnItemFactory,
        ReturnItemResource $returnItemResource,
        CollectionFactory $collectionFactory,
        ReturnItemSearchResultsFactory $searchResultsFactory,
        CollectionProcessorInterface $collectionProcessor,
        ExchangeResource $exchangeResource,
        AllocationGuard $allocationGuard,
        FreshOrderLoader $freshOrderLoader,
        RemainingQuantityCalculatorInterface $remainingQuantityCalculator,
        AllocationValidatorInterface $allocationValidator,
        ReturnItemQuantityValidator $quantityValidator,
        ReturnableOrderItemValidator $returnableOrderItemValidator,
        DecimalMath $moneyMath,
        DecimalMath $quantityMath,
        FinancialRowCalculatorInterface $financialRowCalculator,
        ReturnItemLifecycleValidator $lifecycleValidator,
        VersionGuard $versionGuard,
        AggregateVersionBumper $aggregateVersionBumper,
        ExchangeFactory $exchangeFactory,
        OrderMutexInterface $orderMutex,
        CanonicalRefundedQuantity $canonicalRefundedQuantity
    ) {
        $this->returnItemFactory = $returnItemFactory;
        $this->returnItemResource = $returnItemResource;
        $this->collectionFactory = $collectionFactory;
        $this->searchResultsFactory = $searchResultsFactory;
        $this->collectionProcessor = $collectionProcessor;
        $this->exchangeResource = $exchangeResource;
        $this->allocationGuard = $allocationGuard;
        $this->freshOrderLoader = $freshOrderLoader;
        $this->remainingQuantityCalculator = $remainingQuantityCalculator;
        $this->allocationValidator = $allocationValidator;
        $this->quantityValidator = $quantityValidator;
        $this->returnableOrderItemValidator = $returnableOrderItemValidator;
        $this->moneyMath = $moneyMath;
        $this->quantityMath = $quantityMath;
        $this->financialRowCalculator = $financialRowCalculator;
        $this->lifecycleValidator = $lifecycleValidator;
        $this->versionGuard = $versionGuard;
        $this->aggregateVersionBumper = $aggregateVersionBumper;
        $this->exchangeFactory = $exchangeFactory;
        $this->orderMutex = $orderMutex;
        $this->canonicalRefundedQuantity = $canonicalRefundedQuantity;
    }

    /**
     * @inheritdoc
     */
    public function save(ReturnItemInterface $returnItem): ReturnItemInterface
    {
        if (!$returnItem instanceof ReturnItem) {
            throw new CouldNotSaveException(__('The return item data implementation is not supported.'));
        }

        $isNew = $returnItem->getEntityId() === null;
        if ($isNew) {
            $returnItem->setVersion(VersionGuard::INITIAL_VERSION);
            $returnItem->unsetData(ReturnItemInterface::CREATED_AT);
            $returnItem->unsetData(ReturnItemInterface::UPDATED_AT);
        }
        if ($returnItem->getExchangeId() <= 0 || $returnItem->getOrderItemId() <= 0) {
            throw new InvariantViolationException(
                __('An exchange case and original order item are required.')
            );
        }

        $orderId = $this->resolveOriginalOrderId(
            $returnItem->getExchangeId()
        );
        /** @var ReturnItemInterface $saved */
        $saved = $this->orderMutex->execute(
            $orderId,
            \Closure::fromCallable([$this, 'saveLocked']),
            [$returnItem, $orderId]
        );

        return $saved;
    }

    /**
     * Persist after acquiring the shared sales-order mutex.
     *
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
     */
    private function saveLocked(
        ReturnItemInterface $returnItem,
        int $orderId
    ): ReturnItemInterface {
        $connection = $this->returnItemResource->getConnection();
        $connection->beginTransaction();
        try {
            $exchange = $this->lockWritableExchange($returnItem->getExchangeId());
            if ((int)$exchange['original_order_id'] !== $orderId) {
                throw new InvariantViolationException(
                    __('The exchange original order changed while locking.')
                );
            }
            $persisted = $this->lockPersistedItem($returnItem);
            $this->allocationGuard->lock($returnItem->getOrderItemId());
            $orderItems = $this->getFreshOrderItems($orderId);
            $orderItemId = $returnItem->getOrderItemId();
            if (!isset($orderItems[$orderItemId])) {
                throw new InvariantViolationException(
                    __('The selected original order item does not exist.')
                );
            }
            $orderItem = $orderItems[$orderItemId];
            $this->validateAndNormalize($returnItem);
            $this->lifecycleValidator->execute(
                $returnItem,
                $persisted,
                (string)$exchange['return_status']
            );
            $this->validateOrderItemAndAllocation(
                $returnItem,
                $orderItem,
                $orderItems,
                $exchange,
                $persisted
            );

            /** @var ReturnItemInterface $saved */
            $saved = $this->persist($returnItem, $this->returnItemResource, 'return item');
            $this->aggregateVersionBumper->execute(
                $returnItem->getExchangeId(),
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
    public function getById(int $returnItemId): ReturnItemInterface
    {
        $returnItem = $this->returnItemFactory->create();
        /** @var ReturnItemInterface $loaded */
        $loaded = $this->requireLoaded(
            $returnItem,
            $this->returnItemResource,
            $returnItemId,
            ReturnItemInterface::ENTITY_ID,
            'return item'
        );

        return $loaded;
    }

    /**
     * @inheritdoc
     */
    public function getList(SearchCriteriaInterface $searchCriteria): ReturnItemSearchResultsInterface
    {
        $results = $this->searchResultsFactory->create();
        /** @var ReturnItemSearchResultsInterface $results */
        return $this->buildSearchResults(
            $this->collectionFactory->create(),
            $searchCriteria,
            $results,
            $this->collectionProcessor
        );
    }

    private function validateAndNormalize(ReturnItemInterface $returnItem): void
    {
        $this->quantityValidator->execute(
            $returnItem->getRequestedQty(),
            $returnItem->getAllocatedQty(),
            $returnItem->getReceivedQty(),
            $returnItem->getAcceptedQty(),
            $returnItem->getRejectedQty(),
            $returnItem->getCreditedQty()
        );
        $returnItem->setRequestedQty($this->quantityMath->normalize($returnItem->getRequestedQty()));
        $returnItem->setAllocatedQty($this->quantityMath->normalize($returnItem->getAllocatedQty()));
        $returnItem->setReceivedQty($this->quantityMath->normalize($returnItem->getReceivedQty()));
        $returnItem->setAcceptedQty($this->quantityMath->normalize($returnItem->getAcceptedQty()));
        $returnItem->setCreditedQty($this->quantityMath->normalize($returnItem->getCreditedQty()));
        $returnItem->setRejectedQty($this->quantityMath->normalize($returnItem->getRejectedQty()));
        $returnItem->setUnitCreditAmount(
            $this->moneyMath->assertNonNegative(
                $returnItem->getUnitCreditAmount(),
                'Return unit credit'
            )
        );
        $incomingRowCredit = $this->moneyMath->assertNonNegative(
            $returnItem->getRowCreditAmount(),
            'Return row credit'
        );
        $calculatedRowCredit = $this->financialRowCalculator->execute(
            $returnItem->getAcceptedQty(),
            $returnItem->getUnitCreditAmount()
        );
        if ($this->moneyMath->compare($incomingRowCredit, $calculatedRowCredit) !== 0) {
            throw new InvariantViolationException(
                __('Return row credit must equal accepted quantity multiplied by unit credit.')
            );
        }
        $returnItem->setRowCreditAmount($calculatedRowCredit);
    }

    /**
     * @return array<string, mixed>
     */
    private function lockWritableExchange(int $exchangeId): array
    {
        $exchange = $this->exchangeResource->getDataForUpdate($exchangeId);
        if ($exchange === null) {
            throw new NoSuchEntityException(__('No exchange case exists for ID "%1".', $exchangeId));
        }

        if (in_array(
            (string)$exchange['exchange_status'],
            ExchangeStatus::terminal(),
            true
        ) || in_array((string)$exchange['return_status'], ReturnStatus::terminal(), true)) {
            throw new InvariantViolationException(
                __('Return items cannot be changed after their workflow is closed.')
            );
        }

        return $exchange;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lockPersistedItem(ReturnItem $returnItem): ?array
    {
        if ($returnItem->getEntityId() === null) {
            return null;
        }

        $persisted = $this->returnItemResource->getDataForUpdate($returnItem->getEntityId());
        if ($persisted === null) {
            throw new NoSuchEntityException(
                __('No return item exists for ID "%1".', $returnItem->getEntityId())
            );
        }
        if ((int)$persisted[ReturnItemInterface::EXCHANGE_ID] !== $returnItem->getExchangeId()
            || (int)$persisted[ReturnItemInterface::ORDER_ITEM_ID] !== $returnItem->getOrderItemId()
        ) {
            throw new InvariantViolationException(
                __('A return item cannot be moved to another exchange case or order item.')
            );
        }
        $returnItem->setVersion(
            $this->versionGuard->assertCurrentAndIncrement(
                $returnItem->getVersion(),
                (int)$persisted[ReturnItemInterface::VERSION],
                'return item'
            )
        );
        $returnItem->setCreatedAt((string)$persisted[ReturnItemInterface::CREATED_AT]);
        $returnItem->unsetData(ReturnItemInterface::UPDATED_AT);

        return $persisted;
    }

    /**
     * @param array<int, OrderItemInterface> $orderItems
     * @param array<string, mixed> $exchange
     * @param array<string, mixed>|null $persisted
     */
    private function validateOrderItemAndAllocation(
        ReturnItemInterface $returnItem,
        OrderItemInterface $orderItem,
        array $orderItems,
        array $exchange,
        ?array $persisted
    ): void {
        $this->returnableOrderItemValidator->execute($orderItem);
        if ((int)$orderItem->getOrderId() !== (int)$exchange['original_order_id']) {
            throw new InvariantViolationException(
                __('The selected order item does not belong to the exchange original order.')
            );
        }
        $returnItem->setSku((string)$orderItem->getSku());
        $returnItem->setName((string)$orderItem->getName());

        $allocatedByAllCases = $this->quantityMath->normalize(
            $this->returnItemResource->getAllocatedQuantity($returnItem->getOrderItemId())
        );
        $currentAllocation = $persisted === null
            ? '0.0000'
            : $this->quantityMath->normalize((string)$persisted[ReturnItemInterface::ALLOCATED_QTY]);
        $allocatedByOtherCases = $this->quantityMath->subtract(
            $allocatedByAllCases,
            $currentAllocation
        );
        $remaining = $this->remainingQuantityCalculator->execute(
            (string)$orderItem->getQtyInvoiced(),
            $this->canonicalRefundedQuantity->execute(
                $orderItem,
                $orderItems
            ),
            $allocatedByOtherCases
        );
        $this->allocationValidator->execute($returnItem->getRequestedQty(), $remaining);
        $this->allocationValidator->execute($returnItem->getAllocatedQty(), $remaining);
    }

    /**
     * @return array<int, OrderItemInterface>
     */
    private function getFreshOrderItems(int $orderId): array
    {
        $order = $this->freshOrderLoader->execute($orderId);
        $indexed = [];
        foreach ((array)$order->getItems() as $orderItem) {
            if ($orderItem instanceof OrderItemInterface
                && (int)$orderItem->getItemId() > 0
            ) {
                $indexed[(int)$orderItem->getItemId()] = $orderItem;
            }
        }

        return $indexed;
    }

    private function resolveOriginalOrderId(int $exchangeId): int
    {
        $exchange = $this->exchangeFactory->create();
        $this->exchangeResource->load($exchange, $exchangeId);
        if ($exchange->getEntityId() === null) {
            throw new NoSuchEntityException(
                __('No exchange case exists for ID "%1".', $exchangeId)
            );
        }
        $orderId = $exchange->getOriginalOrderId();
        if ($orderId <= 0) {
            throw new InvariantViolationException(
                __('The exchange requires a valid original order.')
            );
        }

        return $orderId;
    }
}
