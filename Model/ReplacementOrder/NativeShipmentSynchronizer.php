<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\ReplacementOrder;

use Bonlineco\SalesExchange\Api\Data\ExchangeInterface;
use Bonlineco\SalesExchange\Api\ExchangeRepositoryInterface;
use Bonlineco\SalesExchange\Api\Status\ReplacementStatus;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Exchange;
use Bonlineco\SalesExchange\Model\ExchangeFactory;
use Bonlineco\SalesExchange\Model\Order\FreshOrderLoader;
use Bonlineco\SalesExchange\Model\ResourceModel\DocumentLink as DocumentLinkResource;
use Bonlineco\SalesExchange\Model\ResourceModel\Exchange as ExchangeResource;
use Bonlineco\SalesExchange\Model\ResourceModel\ReplacementItem as ReplacementItemResource;
use Bonlineco\SalesExchange\Model\ResourceModel\ReturnItem as ReturnItemResource;
use Bonlineco\SalesExchange\Model\ResourceModel\Settlement as SettlementResource;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Model\OrderMutexInterface;
use Psr\Log\LoggerInterface;

/**
 * Keep native full shipment and exchange fulfillment in one transaction.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
 * @SuppressWarnings(PHPMD.TooManyFields)
 */
class NativeShipmentSynchronizer
{
    private FreshOrderLoader $freshOrderLoader;

    private MarkerReader $markerReader;

    private ExchangeRepositoryInterface $exchangeRepository;

    private ExchangeResource $exchangeResource;

    private ExchangeFactory $exchangeFactory;

    private ReturnItemResource $returnItemResource;

    private ReplacementItemResource $replacementItemResource;

    private DocumentLinkResource $documentLinkResource;

    private SettlementResource $settlementResource;

    private ShipmentRepositoryInterface $shipmentRepository;

    private OrderMutexInterface $orderMutex;

    private NativeShipmentValidator $validator;

    private NativeShipmentWriter $writer;

    private ManagerInterface $eventManager;

    private LoggerInterface $logger;

    public function __construct(
        FreshOrderLoader $freshOrderLoader,
        MarkerReader $markerReader,
        ExchangeRepositoryInterface $exchangeRepository,
        ExchangeResource $exchangeResource,
        ExchangeFactory $exchangeFactory,
        ReturnItemResource $returnItemResource,
        ReplacementItemResource $replacementItemResource,
        DocumentLinkResource $documentLinkResource,
        SettlementResource $settlementResource,
        ShipmentRepositoryInterface $shipmentRepository,
        OrderMutexInterface $orderMutex,
        NativeShipmentValidator $validator,
        NativeShipmentWriter $writer,
        ManagerInterface $eventManager,
        LoggerInterface $logger
    ) {
        $this->freshOrderLoader = $freshOrderLoader;
        $this->markerReader = $markerReader;
        $this->exchangeRepository = $exchangeRepository;
        $this->exchangeResource = $exchangeResource;
        $this->exchangeFactory = $exchangeFactory;
        $this->returnItemResource = $returnItemResource;
        $this->replacementItemResource = $replacementItemResource;
        $this->documentLinkResource = $documentLinkResource;
        $this->settlementResource = $settlementResource;
        $this->shipmentRepository = $shipmentRepository;
        $this->orderMutex = $orderMutex;
        $this->validator = $validator;
        $this->writer = $writer;
        $this->eventManager = $eventManager;
        $this->logger = $logger;
    }

    /**
     * @param array<int, mixed> $requestedItems
     */
    public function execute(
        int $orderId,
        array $requestedItems,
        bool $notify,
        callable $proceed
    ): int {
        $probe = $this->freshOrderLoader->execute($orderId);
        $marker = $this->markerReader->execute($probe);
        if ($marker === null) {
            return (int)$proceed();
        }
        if ($notify) {
            throw new InvariantViolationException(
                __(
                    'Replacement shipment notification must be sent from the '
                    . 'post-commit exchange shipment event.'
                )
            );
        }

        try {
            $initial = $this->exchangeRepository->getById(
                $marker['exchange_id']
            );
            $originalOrderId = $initial->getOriginalOrderId();
            $this->assertLockIdentity($originalOrderId, $orderId);
            /** @var array{
             *     shipment_id: int,
             *     exchange: ExchangeInterface,
             *     order: OrderInterface,
             *     link: \Bonlineco\SalesExchange\Api\Data\DocumentLinkInterface|null,
             *     changed: bool
             * } $result
             */
            $result = $this->orderMutex->execute(
                $originalOrderId,
                function () use (
                    $marker,
                    $originalOrderId,
                    $orderId,
                    $requestedItems,
                    $proceed
                ): array {
                    /** @var array{
                     *     shipment_id: int,
                     *     exchange: ExchangeInterface,
                     *     order: OrderInterface,
                     *     link: \Bonlineco\SalesExchange\Api\Data\DocumentLinkInterface|null,
                     *     changed: bool
                     * } */
                    return $this->orderMutex->execute(
                        $orderId,
                        \Closure::fromCallable([$this, 'shipLocked']),
                        [
                            $marker['exchange_id'],
                            $originalOrderId,
                            $orderId,
                            $marker['intent_hash'],
                            $requestedItems,
                            $proceed,
                        ]
                    );
                }
            );
        } catch (\Throwable $exception) {
            if ($exception instanceof LocalizedException) {
                throw $exception;
            }
            throw new CouldNotSaveException(
                __('The native replacement shipment could not be synchronized.'),
                $exception
            );
        }

        if ($result['changed']) {
            try {
                $this->eventManager->dispatch(
                    'bonlineco_sales_exchange_replacement_order_shipped',
                    [
                        'exchange_id' => (int)$result[
                            'exchange'
                        ]->getEntityId(),
                        'exchange' => $result['exchange'],
                        'order' => $result['order'],
                        'shipment_id' => $result['shipment_id'],
                        'document_link' => $result['link'],
                    ]
                );
            } catch (\Throwable $exception) {
                // Native and module records are already durable.
                $this->logger->critical($exception);
            }
        }

        return $result['shipment_id'];
    }

    /**
     * @param array<int, mixed> $requestedItems
     * @return array{
     *     shipment_id: int,
     *     exchange: ExchangeInterface,
     *     order: OrderInterface,
     *     link: \Bonlineco\SalesExchange\Api\Data\DocumentLinkInterface|null,
     *     changed: bool
     * }
     */
    private function shipLocked(
        int $exchangeId,
        int $originalOrderId,
        int $replacementOrderId,
        string $intentHash,
        array $requestedItems,
        callable $proceed
    ): array {
        $state = $this->loadState($exchangeId, $originalOrderId);
        $order = $this->freshOrderLoader->execute($replacementOrderId);
        $this->assertMarker(
            $order,
            $exchangeId,
            $intentHash,
            $replacementOrderId
        );
        $originalOrder = $this->freshOrderLoader->execute($originalOrderId);
        if (in_array(
            $state['exchange']->getReplacementStatus(),
            [
                ReplacementStatus::SHIPPED,
                ReplacementStatus::DELIVERED,
            ],
            true
        )) {
            return [
                'shipment_id' => $this->validator->replayShipment(
                    $state['exchange'],
                    $order,
                    $originalOrder,
                    $requestedItems,
                    $state['replacement_rows'],
                    $state['document_rows'],
                    $state['settlement_rows'],
                    $intentHash
                ),
                'exchange' => $state['exchange'],
                'order' => $order,
                'link' => null,
                'changed' => false,
            ];
        }
        $orderSnapshot = $this->validator->beforeShipment(
            $state['exchange'],
            $order,
            $originalOrder,
            $state['replacement_rows'],
            $state['document_rows'],
            $state['settlement_rows'],
            $intentHash
        );
        $this->validator->assertFullRequest(
            $requestedItems,
            $orderSnapshot
        );

        $shipmentId = (int)$proceed();
        if ($shipmentId <= 0) {
            throw new InvariantViolationException(
                __('Magento did not create the native replacement shipment.')
            );
        }
        $shippedOrder = $this->freshOrderLoader->execute(
            $replacementOrderId
        );
        $this->assertMarker(
            $shippedOrder,
            $exchangeId,
            $intentHash,
            $replacementOrderId
        );
        $shipment = $this->shipmentRepository->get($shipmentId);
        $shipmentSnapshot = $this->validator->shipmentSnapshot(
            $shipment,
            $shippedOrder,
            $orderSnapshot
        );
        $written = $this->writer->execute(
            $state['exchange'],
            $state['row'],
            $shippedOrder,
            $shipment,
            $shipmentSnapshot
        );

        return [
            'shipment_id' => $shipmentId,
            'exchange' => $written['exchange'],
            'order' => $shippedOrder,
            'link' => $written['link'],
            'changed' => true,
        ];
    }

    /**
     * @return array{
     *     row: array<string, mixed>,
     *     exchange: ExchangeInterface,
     *     return_rows: array<int, array<string, mixed>>,
     *     replacement_rows: array<int, array<string, mixed>>,
     *     document_rows: array<int, array<string, mixed>>,
     *     settlement_rows: array<int, array<string, mixed>>
     * }
     */
    private function loadState(int $exchangeId, int $originalOrderId): array
    {
        $row = $this->exchangeResource->getDataForUpdate($exchangeId);
        if ($row === null) {
            throw new NoSuchEntityException(
                __('No exchange case exists for ID "%1".', $exchangeId)
            );
        }
        if ((int)$row[ExchangeInterface::ORIGINAL_ORDER_ID]
            !== $originalOrderId
        ) {
            throw new InvariantViolationException(
                __('The shipment exchange identity changed while locking.')
            );
        }
        /** @var Exchange $exchange */
        $exchange = $this->exchangeFactory->create();
        $exchange->setData($row);

        return [
            'row' => $row,
            'exchange' => $exchange,
            'return_rows' => $this->returnItemResource
                ->getRowsByExchangeIdForUpdate($exchangeId),
            'replacement_rows' => $this->replacementItemResource
                ->getRowsByExchangeIdForUpdate($exchangeId),
            'document_rows' => $this->documentLinkResource
                ->getRowsByExchangeIdForUpdate($exchangeId),
            'settlement_rows' => $this->settlementResource
                ->getRowsByExchangeIdForUpdate($exchangeId),
        ];
    }

    private function assertMarker(
        OrderInterface $order,
        int $exchangeId,
        string $intentHash,
        int $orderId
    ): void {
        $marker = $this->markerReader->execute($order);
        if ($marker === null
            || $marker['exchange_id'] !== $exchangeId
            || !hash_equals($intentHash, $marker['intent_hash'])
            || (int)$order->getEntityId() !== $orderId
        ) {
            throw new InvariantViolationException(
                __('The replacement shipment order identity changed while locking.')
            );
        }
    }

    private function assertLockIdentity(
        int $originalOrderId,
        int $replacementOrderId
    ): void {
        if ($originalOrderId <= 0
            || $replacementOrderId <= 0
            || $originalOrderId === $replacementOrderId
        ) {
            throw new InvariantViolationException(
                __('The replacement shipment order lock identity is invalid.')
            );
        }
    }
}
