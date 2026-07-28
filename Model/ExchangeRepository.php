<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model;

use Bonlineco\SalesExchange\Api\BalanceCalculatorInterface;
use Bonlineco\SalesExchange\Api\Data\ExchangeInterface;
use Bonlineco\SalesExchange\Api\Data\ExchangeSearchResultsInterface;
use Bonlineco\SalesExchange\Api\ExchangeRepositoryInterface;
use Bonlineco\SalesExchange\Api\History\ActorType;
use Bonlineco\SalesExchange\Api\Status\ExchangeStatus;
use Bonlineco\SalesExchange\Api\Status\ReplacementStatus;
use Bonlineco\SalesExchange\Api\Status\ReturnStatus;
use Bonlineco\SalesExchange\Api\Status\SettlementStatus;
use Bonlineco\SalesExchange\Api\Status\StateDimension;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Creditmemo\ReturnCreditProjection;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Bonlineco\SalesExchange\Model\ResourceModel\Exchange as ExchangeResource;
use Bonlineco\SalesExchange\Model\ResourceModel\Exchange\CollectionFactory;
use Bonlineco\SalesExchange\Model\ResourceModel\History as HistoryResource;
use Bonlineco\SalesExchange\Model\ResourceModel\ReturnItem as ReturnItemResource;
use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Math\Random;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Exchange case repository with calculated balance and guarded status writes.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ExchangeRepository extends AbstractRepository implements ExchangeRepositoryInterface
{
    private ExchangeFactory $exchangeFactory;

    private ExchangeResource $exchangeResource;

    private CollectionFactory $collectionFactory;

    private ExchangeSearchResultsFactory $searchResultsFactory;

    private CollectionProcessorInterface $collectionProcessor;

    private BalanceCalculatorInterface $balanceCalculator;

    private Random $random;

    private HistoryFactory $historyFactory;

    private HistoryResource $historyResource;

    private DecimalMath $decimalMath;

    private VersionGuard $versionGuard;

    private OrderRepositoryInterface $orderRepository;

    private ReturnItemResource $returnItemResource;

    private ReturnCreditProjection $returnCreditProjection;

    private NativeReplacementProjection $nativeReplacementProjection;

    public function __construct(
        ExchangeFactory $exchangeFactory,
        ExchangeResource $exchangeResource,
        CollectionFactory $collectionFactory,
        ExchangeSearchResultsFactory $searchResultsFactory,
        CollectionProcessorInterface $collectionProcessor,
        BalanceCalculatorInterface $balanceCalculator,
        Random $random,
        HistoryFactory $historyFactory,
        HistoryResource $historyResource,
        DecimalMath $decimalMath,
        VersionGuard $versionGuard,
        OrderRepositoryInterface $orderRepository,
        ReturnItemResource $returnItemResource,
        ReturnCreditProjection $returnCreditProjection,
        NativeReplacementProjection $nativeReplacementProjection
    ) {
        $this->exchangeFactory = $exchangeFactory;
        $this->exchangeResource = $exchangeResource;
        $this->collectionFactory = $collectionFactory;
        $this->searchResultsFactory = $searchResultsFactory;
        $this->collectionProcessor = $collectionProcessor;
        $this->balanceCalculator = $balanceCalculator;
        $this->random = $random;
        $this->historyFactory = $historyFactory;
        $this->historyResource = $historyResource;
        $this->decimalMath = $decimalMath;
        $this->versionGuard = $versionGuard;
        $this->orderRepository = $orderRepository;
        $this->returnItemResource = $returnItemResource;
        $this->returnCreditProjection = $returnCreditProjection;
        $this->nativeReplacementProjection = $nativeReplacementProjection;
    }

    /**
     * @inheritdoc
     */
    public function save(ExchangeInterface $exchange): ExchangeInterface
    {
        if (!$exchange instanceof Exchange) {
            throw new CouldNotSaveException(__('The exchange data implementation is not supported.'));
        }

        $isNew = $exchange->getEntityId() === null;
        if ($isNew) {
            $this->initializeNewExchange($exchange);
        }
        $connection = $this->exchangeResource->getConnection();
        $connection->beginTransaction();
        try {
            if (!$isNew) {
                $persisted = $this->exchangeResource->getDataForUpdate(
                    (int)$exchange->getEntityId()
                );
                if ($persisted === null) {
                    throw new \Magento\Framework\Exception\NoSuchEntityException(
                        __('No exchange case exists for ID "%1".', $exchange->getEntityId())
                    );
                }
                $this->assertIdentityWasNotChanged($exchange, $persisted);
                $this->assertStatusesWereNotMutated($exchange, $persisted);
                $this->assertCaseIsWritable($exchange, $persisted);
                $exchange->setVersion(
                    $this->versionGuard->assertCurrentAndIncrement(
                        $exchange->getVersion(),
                        (int)$persisted[ExchangeInterface::VERSION],
                        'exchange case'
                    )
                );
                $exchange->setCreatedAt((string)$persisted[ExchangeInterface::CREATED_AT]);
                $exchange->unsetData(ExchangeInterface::UPDATED_AT);
            }
            $this->validateExchange($exchange);
            $returnRows = $isNew
                ? []
                : $this->returnItemResource->getRowsByExchangeId(
                    (int)$exchange->getEntityId()
                );
            $effectiveReturnCredit = in_array(
                $exchange->getReturnStatus(),
                [ReturnStatus::ACCEPTED, ReturnStatus::PARTIALLY_ACCEPTED],
                true
            )
                ? $this->returnCreditProjection->execute(
                    $exchange->getNativeReturnCreditAmount(),
                    $returnRows
                )
                : '0.0000';
            $exchange->setBalanceAmount(
                $this->balanceCalculator->execute(
                    $this->nativeReplacementProjection->execute(
                        $exchange->getReplacementStatus(),
                        $exchange->getReplacementAmount(),
                        $exchange->getShippingAmount(),
                        $exchange->getNativeReplacementAmount()
                    ),
                    '0.0000',
                    $exchange->getFeeAmount(),
                    $effectiveReturnCredit
                )
            );

            /** @var ExchangeInterface $saved */
            $saved = $this->persist($exchange, $this->exchangeResource, 'exchange case');
            if ($isNew) {
                $this->recordInitialStates($saved);
            }
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
    public function getById(int $exchangeId): ExchangeInterface
    {
        $exchange = $this->exchangeFactory->create();
        /** @var ExchangeInterface $loaded */
        $loaded = $this->requireLoaded(
            $exchange,
            $this->exchangeResource,
            $exchangeId,
            ExchangeInterface::ENTITY_ID,
            'exchange case'
        );
        return $loaded;
    }

    /**
     * @inheritdoc
     */
    public function getByIncrementId(string $incrementId): ExchangeInterface
    {
        $exchange = $this->exchangeFactory->create();
        /** @var ExchangeInterface $loaded */
        $loaded = $this->requireLoaded(
            $exchange,
            $this->exchangeResource,
            $incrementId,
            ExchangeInterface::INCREMENT_ID,
            'exchange case'
        );
        return $loaded;
    }

    /**
     * @inheritdoc
     */
    public function getList(SearchCriteriaInterface $searchCriteria): ExchangeSearchResultsInterface
    {
        $results = $this->searchResultsFactory->create();
        /** @var ExchangeSearchResultsInterface $results */
        return $this->buildSearchResults(
            $this->collectionFactory->create(),
            $searchCriteria,
            $results,
            $this->collectionProcessor
        );
    }

    private function initializeNewExchange(Exchange $exchange): void
    {
        if ($exchange->getOriginalOrderId() <= 0) {
            throw new InvariantViolationException(__('An original order is required.'));
        }
        $order = $this->orderRepository->get($exchange->getOriginalOrderId());
        $exchange->setStoreId($order->getStoreId() === null ? null : (int)$order->getStoreId());
        $exchange->setCustomerId(
            $order->getCustomerId() === null ? null : (int)$order->getCustomerId()
        );
        $exchange->setCurrencyCode((string)$order->getOrderCurrencyCode());
        $exchange->setBaseCurrencyCode((string)$order->getBaseCurrencyCode());
        $exchange->setIncrementId('EX-' . strtoupper($this->random->getRandomString(16)));
        $exchange->setExchangeStatus(ExchangeStatus::DRAFT);
        $exchange->setReturnStatus(ReturnStatus::PENDING);
        $exchange->setReplacementStatus(ReplacementStatus::PENDING);
        $exchange->setSettlementStatus(SettlementStatus::PENDING);
        $exchange->setVersion(VersionGuard::INITIAL_VERSION);
        $exchange->unsetData(ExchangeInterface::CREATED_AT);
        $exchange->unsetData(ExchangeInterface::UPDATED_AT);
        $exchange->setReturnCreditAmount('0.0000');
        $exchange->setNativeReturnCreditAmount('0.0000');
        $exchange->setBaseNativeReturnCreditAmount('0.0000');
        $exchange->setNativeReplacementAmount('0.0000');
        $exchange->setBaseNativeReplacementAmount('0.0000');
        $exchange->setReplacementAmount('0.0000');
        if ($exchange->getShippingAmount() === '') {
            $exchange->setShippingAmount('0.0000');
        }
        if ($exchange->getFeeAmount() === '') {
            $exchange->setFeeAmount('0.0000');
        }
    }

    private function validateExchange(ExchangeInterface $exchange): void
    {
        if ($exchange->getOriginalOrderId() <= 0) {
            throw new InvariantViolationException(__('An original order is required.'));
        }
        if (!preg_match('/^[A-Z]{3}$/D', $exchange->getCurrencyCode())) {
            throw new InvariantViolationException(__('Currency code must be a three-letter uppercase ISO code.'));
        }
        if (!preg_match('/^[A-Z]{3}$/D', $exchange->getBaseCurrencyCode())) {
            throw new InvariantViolationException(
                __('Base currency code must be a three-letter uppercase ISO code.')
            );
        }
        $validStatuses = [
            StateDimension::EXCHANGE => [
                $exchange->getExchangeStatus(),
                ExchangeStatus::all(),
            ],
            StateDimension::RETURN => [
                $exchange->getReturnStatus(),
                ReturnStatus::all(),
            ],
            StateDimension::REPLACEMENT => [
                $exchange->getReplacementStatus(),
                ReplacementStatus::all(),
            ],
            StateDimension::SETTLEMENT => [
                $exchange->getSettlementStatus(),
                SettlementStatus::all(),
            ],
        ];
        foreach ($validStatuses as $dimension => [$status, $allowed]) {
            if (!in_array($status, $allowed, true)) {
                throw new InvariantViolationException(
                    __('Unknown %1 status "%2".', $dimension, $status)
                );
            }
        }

        $exchange->setReturnCreditAmount(
            $this->decimalMath->assertNonNegative(
                $exchange->getReturnCreditAmount(),
                'Return credit amount'
            )
        );
        $exchange->setNativeReturnCreditAmount(
            $this->decimalMath->assertNonNegative(
                $exchange->getNativeReturnCreditAmount(),
                'Native return credit amount'
            )
        );
        $exchange->setBaseNativeReturnCreditAmount(
            $this->decimalMath->assertNonNegative(
                $exchange->getBaseNativeReturnCreditAmount(),
                'Native base return credit amount'
            )
        );
        $exchange->setNativeReplacementAmount(
            $this->decimalMath->assertNonNegative(
                $exchange->getNativeReplacementAmount(),
                'Native replacement amount'
            )
        );
        $exchange->setBaseNativeReplacementAmount(
            $this->decimalMath->assertNonNegative(
                $exchange->getBaseNativeReplacementAmount(),
                'Native base replacement amount'
            )
        );
        $exchange->setReplacementAmount(
            $this->decimalMath->assertNonNegative(
                $exchange->getReplacementAmount(),
                'Replacement amount'
            )
        );
        $exchange->setShippingAmount(
            $this->decimalMath->assertNonNegative(
                $exchange->getShippingAmount(),
                'Shipping amount'
            )
        );
        $exchange->setFeeAmount(
            $this->decimalMath->assertNonNegative($exchange->getFeeAmount(), 'Fee amount')
        );
    }

    /**
     * @param array<string, mixed> $persisted
     */
    private function assertStatusesWereNotMutated(
        ExchangeInterface $exchange,
        array $persisted
    ): void
    {
        $statusPairs = [
            StateDimension::EXCHANGE => [
                (string)$persisted[ExchangeInterface::EXCHANGE_STATUS],
                $exchange->getExchangeStatus(),
            ],
            StateDimension::RETURN => [
                (string)$persisted[ExchangeInterface::RETURN_STATUS],
                $exchange->getReturnStatus(),
            ],
            StateDimension::REPLACEMENT => [
                (string)$persisted[ExchangeInterface::REPLACEMENT_STATUS],
                $exchange->getReplacementStatus(),
            ],
            StateDimension::SETTLEMENT => [
                (string)$persisted[ExchangeInterface::SETTLEMENT_STATUS],
                $exchange->getSettlementStatus(),
            ],
        ];
        foreach ($statusPairs as $dimension => [$before, $after]) {
            if ($before !== $after) {
                throw new InvariantViolationException(
                    __('Use the state transition service to change the %1 status.', $dimension)
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $persisted
     */
    private function assertIdentityWasNotChanged(
        ExchangeInterface $exchange,
        array $persisted
    ): void {
        $matches = (string)$persisted[ExchangeInterface::INCREMENT_ID] === $exchange->getIncrementId()
            && (int)$persisted[ExchangeInterface::ORIGINAL_ORDER_ID] === $exchange->getOriginalOrderId()
            && $this->nullableInt($persisted[ExchangeInterface::STORE_ID]) === $exchange->getStoreId()
            && $this->nullableInt($persisted[ExchangeInterface::CUSTOMER_ID]) === $exchange->getCustomerId()
            && (string)$persisted[ExchangeInterface::CURRENCY_CODE] === $exchange->getCurrencyCode()
            && (string)$persisted[ExchangeInterface::BASE_CURRENCY_CODE]
                === $exchange->getBaseCurrencyCode()
            && (string)$persisted[ExchangeInterface::CREATED_AT] === $exchange->getCreatedAt();
        if (!$matches) {
            throw new InvariantViolationException(
                __('Exchange identity and order snapshots are immutable after creation.')
            );
        }
    }

    /**
     * @param array<string, mixed> $persisted
     */
    private function assertCaseIsWritable(ExchangeInterface $exchange, array $persisted): void
    {
        if (in_array(
            (string)$persisted[ExchangeInterface::EXCHANGE_STATUS],
            ExchangeStatus::terminal(),
            true
        )) {
            throw new InvariantViolationException(__('A closed exchange case is immutable.'));
        }
        $financialFields = [
            ExchangeInterface::RETURN_CREDIT_AMOUNT => $exchange->getReturnCreditAmount(),
            ExchangeInterface::NATIVE_RETURN_CREDIT_AMOUNT
                => $exchange->getNativeReturnCreditAmount(),
            ExchangeInterface::BASE_NATIVE_RETURN_CREDIT_AMOUNT
                => $exchange->getBaseNativeReturnCreditAmount(),
            ExchangeInterface::NATIVE_REPLACEMENT_AMOUNT
                => $exchange->getNativeReplacementAmount(),
            ExchangeInterface::BASE_NATIVE_REPLACEMENT_AMOUNT
                => $exchange->getBaseNativeReplacementAmount(),
            ExchangeInterface::REPLACEMENT_AMOUNT => $exchange->getReplacementAmount(),
            ExchangeInterface::SHIPPING_AMOUNT => $exchange->getShippingAmount(),
            ExchangeInterface::FEE_AMOUNT => $exchange->getFeeAmount(),
            ExchangeInterface::BALANCE_AMOUNT => $exchange->getBalanceAmount(),
        ];
        foreach ($financialFields as $field => $incoming) {
            if ($this->decimalMath->compare(
                (string)$persisted[$field],
                $incoming
            ) !== 0) {
                throw new InvariantViolationException(
                    __(
                        'Use an explicit exchange command to change derived financial totals.'
                    )
                );
            }
        }
    }

    /**
     * @param mixed $value
     */
    private function nullableInt($value): ?int
    {
        return $value === null ? null : (int)$value;
    }

    private function recordInitialStates(ExchangeInterface $exchange): void
    {
        $states = [
            StateDimension::EXCHANGE => $exchange->getExchangeStatus(),
            StateDimension::RETURN => $exchange->getReturnStatus(),
            StateDimension::REPLACEMENT => $exchange->getReplacementStatus(),
            StateDimension::SETTLEMENT => $exchange->getSettlementStatus(),
        ];
        foreach ($states as $dimension => $status) {
            $history = $this->historyFactory->create();
            $history->setExchangeId((int)$exchange->getEntityId())
                ->setAction('case_created')
                ->setStatusDimension($dimension)
                ->setFromValue(null)
                ->setToValue($status)
                ->setActorType(ActorType::SYSTEM)
                ->setActorId(null)
                ->setComment(null);
            $this->historyResource->save($history);
        }
    }
}
