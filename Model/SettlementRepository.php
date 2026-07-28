<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model;

use Bonlineco\SalesExchange\Api\Data\SettlementInterface;
use Bonlineco\SalesExchange\Api\Data\SettlementSearchResultsInterface;
use Bonlineco\SalesExchange\Api\SettlementRepositoryInterface;
use Bonlineco\SalesExchange\Api\Settlement\EntryStatus;
use Bonlineco\SalesExchange\Api\Settlement\Type;
use Bonlineco\SalesExchange\Api\Status\ExchangeStatus;
use Bonlineco\SalesExchange\Api\Status\SettlementStatus;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Bonlineco\SalesExchange\Model\ResourceModel\Exchange as ExchangeResource;
use Bonlineco\SalesExchange\Model\ResourceModel\Settlement as SettlementResource;
use Bonlineco\SalesExchange\Model\ResourceModel\Settlement\CollectionFactory;
use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Idempotent settlement ledger repository.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class SettlementRepository extends AbstractRepository implements SettlementRepositoryInterface
{
    /**
     * @var array<string, string[]>
     */
    private const TRANSITIONS = [
        EntryStatus::PENDING => [
            EntryStatus::PROCESSING,
            EntryStatus::SUCCEEDED,
            EntryStatus::FAILED,
            EntryStatus::CANCELLED,
        ],
        EntryStatus::PROCESSING => [
            EntryStatus::SUCCEEDED,
            EntryStatus::FAILED,
        ],
        EntryStatus::FAILED => [
            EntryStatus::PENDING,
            EntryStatus::PROCESSING,
            EntryStatus::CANCELLED,
        ],
        EntryStatus::SUCCEEDED => [],
        EntryStatus::CANCELLED => [],
    ];

    private SettlementFactory $settlementFactory;

    private SettlementResource $settlementResource;

    private CollectionFactory $collectionFactory;

    private SettlementSearchResultsFactory $searchResultsFactory;

    private CollectionProcessorInterface $collectionProcessor;

    private ExchangeResource $exchangeResource;

    private DecimalMath $decimalMath;

    private VersionGuard $versionGuard;

    private AggregateVersionBumper $aggregateVersionBumper;

    private SettlementIntentValidator $intentValidator;

    public function __construct(
        SettlementFactory $settlementFactory,
        SettlementResource $settlementResource,
        CollectionFactory $collectionFactory,
        SettlementSearchResultsFactory $searchResultsFactory,
        CollectionProcessorInterface $collectionProcessor,
        ExchangeResource $exchangeResource,
        DecimalMath $decimalMath,
        VersionGuard $versionGuard,
        AggregateVersionBumper $aggregateVersionBumper,
        SettlementIntentValidator $intentValidator
    ) {
        $this->settlementFactory = $settlementFactory;
        $this->settlementResource = $settlementResource;
        $this->collectionFactory = $collectionFactory;
        $this->searchResultsFactory = $searchResultsFactory;
        $this->collectionProcessor = $collectionProcessor;
        $this->exchangeResource = $exchangeResource;
        $this->decimalMath = $decimalMath;
        $this->versionGuard = $versionGuard;
        $this->aggregateVersionBumper = $aggregateVersionBumper;
        $this->intentValidator = $intentValidator;
    }

    /**
     * @inheritdoc
     */
    public function save(SettlementInterface $settlement): SettlementInterface
    {
        if (!$settlement instanceof Settlement) {
            throw new CouldNotSaveException(__('The settlement data implementation is not supported.'));
        }

        $isNew = $settlement->getEntityId() === null;
        if ($isNew) {
            $settlement->setStatus(EntryStatus::PENDING);
            $settlement->setVersion(VersionGuard::INITIAL_VERSION);
            $settlement->unsetData(SettlementInterface::CREATED_AT);
            $settlement->unsetData(SettlementInterface::UPDATED_AT);
        }
        if ($settlement->getExchangeId() <= 0) {
            throw new InvariantViolationException(__('An exchange case is required.'));
        }
        $connection = $this->settlementResource->getConnection();
        $connection->beginTransaction();
        try {
            $this->validateAndNormalize($settlement);
            if ($isNew) {
                $idempotent = $this->findByIdempotencyKey($settlement->getIdempotencyKey());
                if ($idempotent !== null) {
                    $this->intentValidator->execute($settlement, $idempotent);
                    $connection->commit();

                    return $idempotent;
                }
            }
            $exchange = $this->exchangeResource->getDataForUpdate($settlement->getExchangeId());
            if ($exchange === null) {
                throw new NoSuchEntityException(
                    __('No exchange case exists for ID "%1".', $settlement->getExchangeId())
                );
            }
            if ($isNew) {
                $idempotent = $this->findByIdempotencyKey($settlement->getIdempotencyKey());
                if ($idempotent !== null) {
                    $this->intentValidator->execute($settlement, $idempotent);
                    $connection->commit();

                    return $idempotent;
                }
            }
            $this->assertCaseIsWritable($exchange);
            if ((string)$exchange['currency_code'] !== $settlement->getCurrencyCode()) {
                throw new InvariantViolationException(
                    __('Settlement currency must match the exchange currency.')
                );
            }

            $this->lockPersistedEntry($settlement);
            /** @var SettlementInterface $saved */
            $saved = $this->persist($settlement, $this->settlementResource, 'settlement entry');
            $this->aggregateVersionBumper->execute(
                $settlement->getExchangeId(),
                (int)$exchange['version']
            );
            $connection->commit();

            return $saved;
        } catch (\Throwable $exception) {
            $connection->rollBack();
            if ($exception instanceof CouldNotSaveException && $isNew) {
                $idempotent = $this->findByIdempotencyKey($settlement->getIdempotencyKey());
                if ($idempotent !== null) {
                    $this->intentValidator->execute($settlement, $idempotent);

                    return $idempotent;
                }
            }
            throw $exception;
        }
    }

    /**
     * @inheritdoc
     */
    public function getById(int $settlementId): SettlementInterface
    {
        $settlement = $this->settlementFactory->create();
        /** @var SettlementInterface $loaded */
        $loaded = $this->requireLoaded(
            $settlement,
            $this->settlementResource,
            $settlementId,
            SettlementInterface::ENTITY_ID,
            'settlement entry'
        );

        return $loaded;
    }

    /**
     * @inheritdoc
     */
    public function getByIdempotencyKey(string $idempotencyKey): SettlementInterface
    {
        $settlement = $this->findByIdempotencyKey($idempotencyKey);
        if ($settlement === null) {
            throw new NoSuchEntityException(
                __('No settlement entry exists for idempotency key "%1".', $idempotencyKey)
            );
        }

        return $settlement;
    }

    /**
     * @inheritdoc
     */
    public function getList(SearchCriteriaInterface $searchCriteria): SettlementSearchResultsInterface
    {
        $results = $this->searchResultsFactory->create();
        /** @var SettlementSearchResultsInterface $results */
        return $this->buildSearchResults(
            $this->collectionFactory->create(),
            $searchCriteria,
            $results,
            $this->collectionProcessor
        );
    }

    private function validateAndNormalize(SettlementInterface $settlement): void
    {
        if (!in_array($settlement->getType(), Type::all(), true)) {
            throw new InvariantViolationException(
                __('Unknown settlement entry type "%1".', $settlement->getType())
            );
        }
        if (!in_array($settlement->getStatus(), EntryStatus::all(), true)) {
            throw new InvariantViolationException(
                __('Unknown settlement entry status "%1".', $settlement->getStatus())
            );
        }
        if (!preg_match('/^[A-Z]{3}$/D', $settlement->getCurrencyCode())) {
            throw new InvariantViolationException(
                __('Currency code must be a three-letter uppercase ISO code.')
            );
        }
        if (trim($settlement->getIdempotencyKey()) === '') {
            throw new InvariantViolationException(__('An idempotency key is required.'));
        }
        if (strlen($settlement->getIdempotencyKey()) > 128) {
            throw new InvariantViolationException(
                __('The idempotency key cannot exceed 128 characters.')
            );
        }
        if (str_starts_with(
            $settlement->getIdempotencyKey(),
            'sales-exchange:settlement:'
        )) {
            throw new InvariantViolationException(
                __('The canonical settlement idempotency namespace is reserved.')
            );
        }
        $externalReference = $settlement->getExternalReference();
        $externalReference = $externalReference === null
            ? ''
            : trim($externalReference);
        if (mb_strlen($externalReference) > 255) {
            throw new InvariantViolationException(
                __('The external settlement reference cannot exceed 255 characters.')
            );
        }
        $settlement->setExternalReference(
            $externalReference === '' ? null : $externalReference
        );
        if ($settlement->getStatus() === EntryStatus::SUCCEEDED
            && in_array(
                $settlement->getType(),
                [Type::CUSTOMER_PAYMENT, Type::MERCHANT_REFUND],
                true
            )
            && $externalReference === ''
        ) {
            throw new InvariantViolationException(
                __('A successful external cash settlement requires an external reference.')
            );
        }

        $settlement->setAmount($this->decimalMath->normalize($settlement->getAmount()));
        $comparison = $this->decimalMath->compare($settlement->getAmount(), '0');
        if ($settlement->getType() === Type::MERCHANT_REFUND && $comparison >= 0) {
            throw new InvariantViolationException(
                __('A merchant refund must use a negative amount.')
            );
        }
        if (in_array(
            $settlement->getType(),
            [Type::RETURN_CREDIT, Type::CUSTOMER_PAYMENT],
            true
        ) && $comparison <= 0) {
            throw new InvariantViolationException(
                __('Return credits and customer payments must use positive amounts.')
            );
        }
        if ($settlement->getType() === Type::ADJUSTMENT && $comparison === 0) {
            throw new InvariantViolationException(__('A settlement adjustment cannot be zero.'));
        }
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
            (string)$exchange['settlement_status'],
            SettlementStatus::terminal(),
            true
        )) {
            throw new InvariantViolationException(
                __('Settlement entries cannot be changed after their workflow is closed.')
            );
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lockPersistedEntry(Settlement $settlement): ?array
    {
        if ($settlement->getEntityId() === null) {
            return null;
        }

        $persisted = $this->settlementResource->getDataForUpdate(
            $settlement->getEntityId()
        );
        if ($persisted === null) {
            throw new NoSuchEntityException(
                __('No settlement entry exists for ID "%1".', $settlement->getEntityId())
            );
        }
        $this->assertImmutableIntent($settlement, $persisted);
        $settlement->setVersion(
            $this->versionGuard->assertCurrentAndIncrement(
                $settlement->getVersion(),
                (int)$persisted[SettlementInterface::VERSION],
                'settlement entry'
            )
        );
        if (in_array(
            (string)$persisted[SettlementInterface::STATUS],
            EntryStatus::terminal(),
            true
        )) {
            throw new InvariantViolationException(
                __('A terminal settlement ledger entry is immutable.')
            );
        }
        $this->assertStatusTransition(
            (string)$persisted[SettlementInterface::STATUS],
            $settlement->getStatus()
        );
        if ($settlement->getStatus() === EntryStatus::CANCELLED
            && (
                trim((string)$persisted[SettlementInterface::EXTERNAL_REFERENCE]) !== ''
                || trim((string)$settlement->getExternalReference()) !== ''
            )
        ) {
            throw new InvariantViolationException(
                __('A settlement entry with an external reference cannot be cancelled directly.')
            );
        }
        $settlement->setCreatedAt(
            (string)$persisted[SettlementInterface::CREATED_AT]
        );
        $settlement->unsetData(SettlementInterface::UPDATED_AT);

        return $persisted;
    }

    /**
     * @param array<string, mixed> $persisted
     */
    private function assertImmutableIntent(SettlementInterface $settlement, array $persisted): void
    {
        $matches = (int)$persisted[SettlementInterface::EXCHANGE_ID] === $settlement->getExchangeId()
            && (string)$persisted[SettlementInterface::TYPE] === $settlement->getType()
            && (string)$persisted[SettlementInterface::AMOUNT] === $settlement->getAmount()
            && (string)$persisted[SettlementInterface::CURRENCY_CODE] === $settlement->getCurrencyCode()
            && (string)$persisted[SettlementInterface::IDEMPOTENCY_KEY] === $settlement->getIdempotencyKey();
        if (!$matches) {
            throw new InvariantViolationException(
                __('A settlement entry financial intent is immutable after creation.')
            );
        }
        $persistedReference = trim(
            (string)($persisted[SettlementInterface::EXTERNAL_REFERENCE] ?? '')
        );
        $incomingReference = trim((string)$settlement->getExternalReference());
        if ($persistedReference !== '' && $incomingReference !== $persistedReference) {
            throw new InvariantViolationException(
                __('An external settlement reference is immutable after it is recorded.')
            );
        }
    }

    private function assertStatusTransition(string $fromStatus, string $toStatus): void
    {
        if ($fromStatus === $toStatus) {
            return;
        }
        if (!isset(self::TRANSITIONS[$fromStatus])
            || !in_array($toStatus, self::TRANSITIONS[$fromStatus], true)
        ) {
            throw new InvariantViolationException(
                __('A settlement entry cannot move from "%1" to "%2".', $fromStatus, $toStatus)
            );
        }
    }

    private function findByIdempotencyKey(string $idempotencyKey): ?SettlementInterface
    {
        $settlement = $this->settlementFactory->create();
        $this->settlementResource->load(
            $settlement,
            $idempotencyKey,
            SettlementInterface::IDEMPOTENCY_KEY
        );

        return $settlement->getEntityId() === null ? null : $settlement;
    }

}
