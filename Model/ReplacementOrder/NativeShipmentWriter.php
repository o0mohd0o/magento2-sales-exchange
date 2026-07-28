<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\ReplacementOrder;

use Bonlineco\SalesExchange\Api\Data\DocumentLinkInterface;
use Bonlineco\SalesExchange\Api\Data\ExchangeInterface;
use Bonlineco\SalesExchange\Api\DocumentType;
use Bonlineco\SalesExchange\Api\History\ActorType;
use Bonlineco\SalesExchange\Api\Status\ReplacementStatus;
use Bonlineco\SalesExchange\Api\Status\StateDimension;
use Bonlineco\SalesExchange\Model\DocumentLinkFactory;
use Bonlineco\SalesExchange\Model\DocumentLinkWriter;
use Bonlineco\SalesExchange\Model\HistoryFactory;
use Bonlineco\SalesExchange\Model\ResourceModel\Exchange as ExchangeResource;
use Bonlineco\SalesExchange\Model\ResourceModel\History as HistoryResource;
use Bonlineco\SalesExchange\Model\Settlement\OperationKeys;
use Bonlineco\SalesExchange\Model\VersionGuard;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\ShipmentInterface;

/**
 * Append the immutable shipment proof and advance the exchange projection.
 */
class NativeShipmentWriter
{
    private DocumentLinkFactory $documentLinkFactory;

    private DocumentLinkWriter $documentLinkWriter;

    private ExchangeResource $exchangeResource;

    private HistoryFactory $historyFactory;

    private HistoryResource $historyResource;

    private OperationKeys $operationKeys;

    private VersionGuard $versionGuard;

    public function __construct(
        DocumentLinkFactory $documentLinkFactory,
        DocumentLinkWriter $documentLinkWriter,
        ExchangeResource $exchangeResource,
        HistoryFactory $historyFactory,
        HistoryResource $historyResource,
        OperationKeys $operationKeys,
        VersionGuard $versionGuard
    ) {
        $this->documentLinkFactory = $documentLinkFactory;
        $this->documentLinkWriter = $documentLinkWriter;
        $this->exchangeResource = $exchangeResource;
        $this->historyFactory = $historyFactory;
        $this->historyResource = $historyResource;
        $this->operationKeys = $operationKeys;
        $this->versionGuard = $versionGuard;
    }

    /**
     * @param array<string, mixed> $exchangeRow
     * @param array{
     *     item_quantities_json: string,
     *     snapshot_hash: string,
     *     document_status: string|null
     * } $snapshot
     * @return array{
     *     exchange: ExchangeInterface,
     *     link: DocumentLinkInterface
     * }
     */
    public function execute(
        ExchangeInterface $exchange,
        array $exchangeRow,
        OrderInterface $order,
        ShipmentInterface $shipment,
        array $snapshot
    ): array {
        $nextVersion = $this->versionGuard->assertCurrentAndIncrement(
            (int)$exchangeRow[ExchangeInterface::VERSION],
            (int)$exchangeRow[ExchangeInterface::VERSION],
            'exchange case'
        );
        $link = $this->documentLinkFactory->create();
        $link->setExchangeId((int)$exchange->getEntityId())
            ->setDocumentType(DocumentType::SHIPMENT)
            ->setDocumentId((int)$shipment->getEntityId())
            ->setIncrementId((string)$shipment->getIncrementId())
            ->setOperationKey(
                $this->operationKeys->replacementShipment(
                    (int)$exchange->getEntityId()
                )
            )
            ->setItemQuantitiesJson($snapshot['item_quantities_json'])
            ->setSnapshotHash($snapshot['snapshot_hash'])
            ->setAmount('0.0000')
            ->setExpectedAmount('0.0000')
            ->setBaseAmount('0.0000')
            ->setCurrencyCode((string)$order->getOrderCurrencyCode())
            ->setBaseCurrencyCode((string)$order->getBaseCurrencyCode())
            ->setDocumentStatus($snapshot['document_status']);
        $link = $this->documentLinkWriter->append($link);

        $exchange->setReplacementStatus(ReplacementStatus::SHIPPED)
            ->setVersion($nextVersion);
        $this->exchangeResource->save($exchange);
        $history = $this->historyFactory->create();
        $history->setExchangeId((int)$exchange->getEntityId())
            ->setAction('native_replacement_order_shipped')
            ->setStatusDimension(StateDimension::REPLACEMENT)
            ->setFromValue(ReplacementStatus::ORDERED)
            ->setToValue(
                sprintf(
                    '%s;shipment=%s',
                    ReplacementStatus::SHIPPED,
                    (string)$shipment->getIncrementId()
                )
            )
            ->setActorType(ActorType::SYSTEM)
            ->setActorId(null)
            ->setComment(
                'Synchronized from a full Magento native shipment.'
            );
        $this->historyResource->save($history);

        return ['exchange' => $exchange, 'link' => $link];
    }
}
