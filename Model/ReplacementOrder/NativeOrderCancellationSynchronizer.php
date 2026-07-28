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
use Magento\Sales\Model\Order as SalesOrder;
use Magento\Sales\Model\OrderMutexInterface;
use Psr\Log\LoggerInterface;

/**
 * Atomically synchronize Magento cancellation into the exchange aggregate.
 *
 * The outer original-order then replacement-order mutexes contain both the
 * native OrderService write and module compensation in one sales transaction.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
 * @SuppressWarnings(PHPMD.TooManyFields)
 */
class NativeOrderCancellationSynchronizer
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

    private OrderMutexInterface $orderMutex;

    private NativeCancellationValidator $validator;

    private NativeCancellationWriter $writer;

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
        OrderMutexInterface $orderMutex,
        NativeCancellationValidator $validator,
        NativeCancellationWriter $writer,
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
        $this->orderMutex = $orderMutex;
        $this->validator = $validator;
        $this->writer = $writer;
        $this->eventManager = $eventManager;
        $this->logger = $logger;
    }

    public function execute(int $orderId, callable $proceed): bool
    {
        $probe = $this->freshOrderLoader->execute($orderId);
        $marker = $this->markerReader->execute($probe);
        if ($marker === null) {
            return (bool)$proceed($orderId);
        }

        try {
            $initial = $this->exchangeRepository->getById(
                $marker['exchange_id']
            );
            $originalOrderId = $initial->getOriginalOrderId();
            $this->assertLockIdentity($originalOrderId, $orderId);
            /** @var array{
             *     native_result: bool,
             *     exchange: ExchangeInterface,
             *     order: OrderInterface,
             *     changed: bool
             * } $result
             */
            $result = $this->orderMutex->execute(
                $originalOrderId,
                function () use (
                    $orderId,
                    $originalOrderId,
                    $marker,
                    $proceed
                ): array {
                    /** @var array{
                     *     native_result: bool,
                     *     exchange: ExchangeInterface,
                     *     order: OrderInterface,
                     *     changed: bool
                     * } */
                    return $this->orderMutex->execute(
                        $orderId,
                        \Closure::fromCallable([$this, 'synchronizeLocked']),
                        [
                            $marker['exchange_id'],
                            $originalOrderId,
                            $orderId,
                            $marker['intent_hash'],
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
                __(
                    'The native replacement order cancellation could not be '
                    . 'synchronized.'
                ),
                $exception
            );
        }

        if ($result['changed']) {
            try {
                $this->eventManager->dispatch(
                    'bonlineco_sales_exchange_replacement_order_cancelled',
                    [
                        'exchange_id' => (int)$result[
                            'exchange'
                        ]->getEntityId(),
                        'exchange' => $result['exchange'],
                        'order' => $result['order'],
                    ]
                );
            } catch (\Throwable $exception) {
                // Native and module records are already durable.
                $this->logger->critical($exception);
            }
        }

        return $result['native_result'];
    }

    /**
     * @return array{
     *     native_result: bool,
     *     exchange: ExchangeInterface,
     *     order: OrderInterface,
     *     changed: bool
     * }
     */
    private function synchronizeLocked(
        int $exchangeId,
        int $originalOrderId,
        int $replacementOrderId,
        string $intentHash,
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
        $wasCancelled = (string)$order->getState()
            === SalesOrder::STATE_CANCELED;
        $this->validator->execute(
            $state['exchange'],
            $order,
            $originalOrder,
            $state['replacement_rows'],
            $state['document_rows'],
            $state['settlement_rows'],
            $intentHash,
            $wasCancelled
        );

        $nativeResult = (bool)$proceed($replacementOrderId);
        $cancelledOrder = $this->freshOrderLoader->execute(
            $replacementOrderId
        );
        $this->assertMarker(
            $cancelledOrder,
            $exchangeId,
            $intentHash,
            $replacementOrderId
        );
        if (!$wasCancelled && !$nativeResult) {
            throw new InvariantViolationException(
                __('Magento did not cancel the native replacement order.')
            );
        }
        $this->validator->execute(
            $state['exchange'],
            $cancelledOrder,
            $originalOrder,
            $state['replacement_rows'],
            $state['document_rows'],
            $state['settlement_rows'],
            $intentHash,
            true
        );
        $written = $this->writer->execute(
            $state['exchange'],
            $state['row'],
            $state['replacement_rows'],
            $state['return_rows'],
            $cancelledOrder
        );

        return [
            'native_result' => $nativeResult,
            'exchange' => $written['exchange'],
            'order' => $cancelledOrder,
            'changed' => $written['changed'],
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
                __(
                    'The exchange original order changed outside the '
                    . 'deterministic cancellation lock set.'
                )
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
                __(
                    'The native replacement order identity changed while '
                    . 'acquiring cancellation locks.'
                )
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
                __('The replacement cancellation order lock identity is invalid.')
            );
        }
    }
}
