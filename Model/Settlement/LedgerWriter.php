<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\Settlement;

use Bonlineco\SalesExchange\Api\Data\SettlementInterface;
use Bonlineco\SalesExchange\Api\Settlement\EntryStatus;
use Bonlineco\SalesExchange\Api\Settlement\Type;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Bonlineco\SalesExchange\Model\ResourceModel\Exchange as ExchangeResource;
use Bonlineco\SalesExchange\Model\ResourceModel\Settlement as SettlementResource;
use Bonlineco\SalesExchange\Model\Settlement;
use Bonlineco\SalesExchange\Model\SettlementFactory;
use Bonlineco\SalesExchange\Model\SettlementIntentValidator;
use Bonlineco\SalesExchange\Model\VersionGuard;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Single writer for immutable, idempotent succeeded settlement postings.
 */
class LedgerWriter
{
    private SettlementFactory $settlementFactory;

    private SettlementResource $settlementResource;

    private ExchangeResource $exchangeResource;

    private SettlementIntentValidator $intentValidator;

    private DecimalMath $moneyMath;

    private OperationKeys $operationKeys;

    public function __construct(
        SettlementFactory $settlementFactory,
        SettlementResource $settlementResource,
        ExchangeResource $exchangeResource,
        SettlementIntentValidator $intentValidator,
        DecimalMath $moneyMath,
        OperationKeys $operationKeys
    ) {
        $this->settlementFactory = $settlementFactory;
        $this->settlementResource = $settlementResource;
        $this->exchangeResource = $exchangeResource;
        $this->intentValidator = $intentValidator;
        $this->moneyMath = $moneyMath;
        $this->operationKeys = $operationKeys;
    }

    /**
     * Append every missing exact posting and reject any unexpected ledger row.
     *
     * @return SettlementInterface[]
     */
    public function appendPlan(Plan $plan, ?string $comment): array
    {
        $connection = $this->settlementResource->getConnection();
        $connection->beginTransaction();
        try {
            $exchange = $this->exchangeResource->getDataForUpdate(
                $plan->getExchangeId()
            );
            if ($exchange === null) {
                throw new NoSuchEntityException(
                    __('No exchange case exists for ID "%1".', $plan->getExchangeId())
                );
            }
            if ((string)$exchange['currency_code'] !== $plan->getCurrencyCode()) {
                throw new InvariantViolationException(
                    __('Settlement ledger currency must match the exchange currency.')
                );
            }
            foreach ($plan->getEntries() as $entry) {
                $requested = $this->createEntry(
                    $plan,
                    $entry['type'],
                    $entry['amount'],
                    $entry['idempotency_key'],
                    $entry['external_reference'],
                    $comment
                );
                $existing = $this->settlementResource
                    ->getByIdempotencyKeyForUpdate($entry['idempotency_key']);
                if ($existing !== null) {
                    $this->intentValidator->execute(
                        $requested,
                        $this->modelFromRow($existing)
                    );
                    continue;
                }
                $this->settlementResource->save($requested);
            }
            $entries = $this->assertExact(
                $plan,
                $this->settlementResource->getRowsByExchangeIdForUpdate(
                    $plan->getExchangeId()
                )
            );
            $connection->commit();

            return $entries;
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }
    }

    /**
     * Validate a completed or partially recovered ledger without writing.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return SettlementInterface[]
     */
    public function assertExact(Plan $plan, array $rows): array
    {
        $result = $this->assertCompatiblePartial($plan, $rows);
        if (count($result) !== count($plan->getEntries())) {
            throw new InvariantViolationException(
                __('The exchange ledger is missing a canonical settlement entry.')
            );
        }

        return $result;
    }

    /**
     * Accept only an exact subset of the canonical immutable postings.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return SettlementInterface[]
     */
    public function assertCompatiblePartial(Plan $plan, array $rows): array
    {
        $expected = [];
        foreach ($plan->getEntries() as $entry) {
            $expected[$entry['idempotency_key']] = $entry;
        }
        if (count($rows) > count($expected)) {
            throw new InvariantViolationException(
                __('The exchange ledger contains missing or unexpected settlement entries.')
            );
        }

        $result = [];
        foreach ($rows as $row) {
            $key = (string)($row[SettlementInterface::IDEMPOTENCY_KEY] ?? '');
            if (!isset($expected[$key])) {
                throw new InvariantViolationException(
                    __('The exchange ledger contains an unexpected settlement entry.')
                );
            }
            $entry = $expected[$key];
            $requested = $this->createEntry(
                $plan,
                $entry['type'],
                $entry['amount'],
                $key,
                $entry['external_reference'],
                null
            );
            $persisted = $this->modelFromRow($row);
            $this->intentValidator->execute($requested, $persisted);
            if ($persisted->getStatus() !== EntryStatus::SUCCEEDED
                || $persisted->getVersion() !== VersionGuard::INITIAL_VERSION
            ) {
                throw new InvariantViolationException(
                    __('Every canonical settlement ledger posting must be immutable and succeeded.')
                );
            }
            $result[] = $persisted;
            unset($expected[$key]);
        }

        return $result;
    }

    private function createEntry(
        Plan $plan,
        string $type,
        string $amount,
        string $idempotencyKey,
        ?string $externalReference,
        ?string $comment
    ): Settlement {
        if (!in_array(
            $type,
            [Type::RETURN_CREDIT, Type::CUSTOMER_PAYMENT, Type::MERCHANT_REFUND],
            true
        ) || !preg_match(
            '/^sales-exchange:settlement:[a-z-]+:v1:[1-9][0-9]*$/D',
            $idempotencyKey
        )
        ) {
            throw new InvariantViolationException(
                __('The canonical settlement posting identity is invalid.')
            );
        }
        $expectedKey = match ($type) {
            Type::RETURN_CREDIT => $this->operationKeys->returnCredit(
                $plan->getExchangeId()
            ),
            Type::CUSTOMER_PAYMENT => $this->operationKeys->customerPayment(
                $plan->getExchangeId()
            ),
            Type::MERCHANT_REFUND => $this->operationKeys->merchantRefund(
                $plan->getExchangeId()
            ),
            default => '',
        };
        if (!hash_equals($expectedKey, $idempotencyKey)) {
            throw new InvariantViolationException(
                __('The canonical settlement posting key belongs to another intent.')
            );
        }
        $normalized = $this->moneyMath->normalize($amount);
        $comparison = $this->moneyMath->compare($normalized, '0');
        if (($type === Type::MERCHANT_REFUND && $comparison >= 0)
            || ($type !== Type::MERCHANT_REFUND && $comparison <= 0)
        ) {
            throw new InvariantViolationException(
                __('The canonical settlement posting amount has the wrong sign.')
            );
        }
        if (in_array($type, [Type::CUSTOMER_PAYMENT, Type::MERCHANT_REFUND], true)
            && trim((string)$externalReference) === ''
        ) {
            throw new InvariantViolationException(
                __('A successful external cash settlement requires an external reference.')
            );
        }
        if ($type === Type::RETURN_CREDIT && $externalReference !== null) {
            throw new InvariantViolationException(
                __('A native return-credit posting cannot carry an external cash reference.')
            );
        }

        /** @var Settlement $settlement */
        $settlement = $this->settlementFactory->create();
        $settlement->setExchangeId($plan->getExchangeId())
            ->setType($type)
            ->setStatus(EntryStatus::SUCCEEDED)
            ->setAmount($normalized)
            ->setCurrencyCode($plan->getCurrencyCode())
            ->setIdempotencyKey($idempotencyKey)
            ->setExternalReference($externalReference)
            ->setComment($comment)
            ->setVersion(VersionGuard::INITIAL_VERSION);
        $settlement->unsetData(SettlementInterface::CREATED_AT);
        $settlement->unsetData(SettlementInterface::UPDATED_AT);

        return $settlement;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function modelFromRow(array $row): Settlement
    {
        /** @var Settlement $settlement */
        $settlement = $this->settlementFactory->create();
        $settlement->setData($row);

        return $settlement;
    }
}
