<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model;

use Bonlineco\SalesExchange\Api\Data\DocumentLinkInterface;
use Bonlineco\SalesExchange\Api\DocumentType;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Bonlineco\SalesExchange\Model\ResourceModel\DocumentLink as DocumentLinkResource;
use Bonlineco\SalesExchange\Model\ResourceModel\Exchange as ExchangeResource;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Internal append-only writer used inside aggregate document transactions.
 */
class DocumentLinkWriter extends AbstractRepository
{
    private DocumentLinkFactory $documentLinkFactory;

    private DocumentLinkResource $documentLinkResource;

    private ExchangeResource $exchangeResource;

    private DecimalMath $moneyMath;

    public function __construct(
        DocumentLinkFactory $documentLinkFactory,
        DocumentLinkResource $documentLinkResource,
        ExchangeResource $exchangeResource,
        DecimalMath $moneyMath
    ) {
        $this->documentLinkFactory = $documentLinkFactory;
        $this->documentLinkResource = $documentLinkResource;
        $this->exchangeResource = $exchangeResource;
        $this->moneyMath = $moneyMath;
    }

    public function append(DocumentLinkInterface $documentLink): DocumentLinkInterface
    {
        if (!$documentLink instanceof DocumentLink) {
            throw new CouldNotSaveException(
                __('The native document link implementation is not supported.')
            );
        }
        if ($documentLink->getEntityId() !== null) {
            throw new InvariantViolationException(
                __('Native document links are immutable after creation.')
            );
        }
        $this->validateAndNormalize($documentLink);
        $connection = $this->documentLinkResource->getConnection();
        $connection->beginTransaction();
        try {
            $exchange = $this->exchangeResource->getDataForUpdate(
                $documentLink->getExchangeId()
            );
            if ($exchange === null) {
                throw new NoSuchEntityException(
                    __('No exchange case exists for ID "%1".', $documentLink->getExchangeId())
                );
            }
            if ((string)$exchange['currency_code'] !== $documentLink->getCurrencyCode()
                || (string)$exchange['base_currency_code']
                    !== $documentLink->getBaseCurrencyCode()
            ) {
                throw new InvariantViolationException(
                    __('Native document currencies must match the exchange order snapshots.')
                );
            }
            $existing = $this->findByOperationKey($documentLink->getOperationKey());
            if ($existing !== null) {
                $this->assertSameIntent($documentLink, $existing);
                $connection->commit();

                return $existing;
            }
            $documentLink->unsetData(DocumentLinkInterface::CREATED_AT);
            /** @var DocumentLinkInterface $saved */
            $saved = $this->persist(
                $documentLink,
                $this->documentLinkResource,
                'native document link'
            );
            $connection->commit();

            return $saved;
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }
    }

    private function validateAndNormalize(DocumentLinkInterface $link): void
    {
        if ($link->getExchangeId() <= 0 || $link->getDocumentId() <= 0) {
            throw new InvariantViolationException(
                __('An exchange and native document are required.')
            );
        }
        if (!in_array($link->getDocumentType(), DocumentType::all(), true)) {
            throw new InvariantViolationException(
                __('Unknown native document type "%1".', $link->getDocumentType())
            );
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9:_-]{7,127}$/D', $link->getOperationKey())) {
            throw new InvariantViolationException(
                __('The native document operation key is invalid.')
            );
        }
        if (!preg_match('/^[a-f0-9]{64}$/D', $link->getSnapshotHash())) {
            throw new InvariantViolationException(
                __('The native document snapshot fingerprint is invalid.')
            );
        }
        foreach ([$link->getCurrencyCode(), $link->getBaseCurrencyCode()] as $currencyCode) {
            if (!preg_match('/^[A-Z]{3}$/D', $currencyCode)) {
                throw new InvariantViolationException(
                    __('Native document currency codes must use three uppercase letters.')
                );
            }
        }
        $link->setAmount(
            $this->moneyMath->assertNonNegative($link->getAmount(), 'Native document amount')
        );
        $link->setExpectedAmount(
            $this->moneyMath->assertNonNegative(
                $link->getExpectedAmount(),
                'Expected native document amount'
            )
        );
        $link->setBaseAmount(
            $this->moneyMath->assertNonNegative(
                $link->getBaseAmount(),
                'Native base document amount'
            )
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

    private function assertSameIntent(
        DocumentLinkInterface $requested,
        DocumentLinkInterface $persisted
    ): void {
        $matches = $requested->getExchangeId() === $persisted->getExchangeId()
            && $requested->getDocumentType() === $persisted->getDocumentType()
            && $requested->getDocumentId() === $persisted->getDocumentId()
            && $requested->getIncrementId() === $persisted->getIncrementId()
            && $requested->getItemQuantitiesJson() === $persisted->getItemQuantitiesJson()
            && hash_equals(
                $requested->getSnapshotHash(),
                $persisted->getSnapshotHash()
            )
            && $requested->getCurrencyCode() === $persisted->getCurrencyCode()
            && $requested->getBaseCurrencyCode() === $persisted->getBaseCurrencyCode()
            && $requested->getDocumentStatus() === $persisted->getDocumentStatus()
            && $this->moneyMath->compare(
                $requested->getAmount(),
                $persisted->getAmount()
            ) === 0
            && $this->moneyMath->compare(
                $requested->getExpectedAmount(),
                $persisted->getExpectedAmount()
            ) === 0
            && $this->moneyMath->compare(
                $requested->getBaseAmount(),
                $persisted->getBaseAmount()
            ) === 0;
        if (!$matches) {
            throw new InvariantViolationException(
                __('The operation key is already linked to a different native document.')
            );
        }
    }
}
