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
use Bonlineco\SalesExchange\Api\ReplacementDeliveryProofProviderInterface;
use Bonlineco\SalesExchange\Api\SynchronizeReplacementDeliveryInterface;
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
use Magento\Sales\Model\OrderMutexInterface;
use Psr\Log\LoggerInterface;

/**
 * Reconcile an adapter-owned delivery proof under deterministic order locks.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
 * @SuppressWarnings(PHPMD.TooManyFields)
 */
class SynchronizeReplacementDelivery implements
    SynchronizeReplacementDeliveryInterface
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

    private ReplacementDeliveryProofProviderInterface $proofProvider;

    private NativeDeliveryValidator $validator;

    private NativeDeliveryWriter $writer;

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
        ReplacementDeliveryProofProviderInterface $proofProvider,
        NativeDeliveryValidator $validator,
        NativeDeliveryWriter $writer,
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
        $this->proofProvider = $proofProvider;
        $this->validator = $validator;
        $this->writer = $writer;
        $this->eventManager = $eventManager;
        $this->logger = $logger;
    }

    public function execute(int $replacementOrderId): ExchangeInterface
    {
        $probe = $this->freshOrderLoader->execute($replacementOrderId);
        $marker = $this->markerReader->execute($probe);
        if ($marker === null) {
            throw new InvariantViolationException(
                __('The order is not a marked replacement order.')
            );
        }

        try {
            $initial = $this->exchangeRepository->getById(
                $marker['exchange_id']
            );
            $originalOrderId = $initial->getOriginalOrderId();
            $this->assertLockIdentity(
                $originalOrderId,
                $replacementOrderId
            );
            /** @var array{exchange: ExchangeInterface, changed: bool} $result */
            $result = $this->orderMutex->execute(
                $originalOrderId,
                function () use (
                    $marker,
                    $originalOrderId,
                    $replacementOrderId
                ): array {
                    /** @var array{exchange: ExchangeInterface, changed: bool} */
                    return $this->orderMutex->execute(
                        $replacementOrderId,
                        \Closure::fromCallable(
                            [$this, 'synchronizeLocked']
                        ),
                        [
                            $marker['exchange_id'],
                            $originalOrderId,
                            $replacementOrderId,
                            $marker['intent_hash'],
                        ]
                    );
                }
            );
        } catch (\Throwable $exception) {
            if ($exception instanceof LocalizedException) {
                throw $exception;
            }
            throw new CouldNotSaveException(
                __('The replacement delivery proof could not be synchronized.'),
                $exception
            );
        }

        if ($result['changed']) {
            try {
                $this->eventManager->dispatch(
                    'bonlineco_sales_exchange_replacement_order_delivered',
                    [
                        'exchange_id' => (int)$result[
                            'exchange'
                        ]->getEntityId(),
                        'exchange' => $result['exchange'],
                        'replacement_order_id' => $replacementOrderId,
                    ]
                );
            } catch (\Throwable $exception) {
                // Module records are already durable.
                $this->logger->critical($exception);
            }
        }

        return $result['exchange'];
    }

    /**
     * @return array{exchange: ExchangeInterface, changed: bool}
     */
    private function synchronizeLocked(
        int $exchangeId,
        int $originalOrderId,
        int $replacementOrderId,
        string $intentHash
    ): array {
        $state = $this->loadState($exchangeId, $originalOrderId);
        $order = $this->freshOrderLoader->execute($replacementOrderId);
        $this->assertMarker(
            $order,
            $exchangeId,
            $intentHash,
            $replacementOrderId
        );
        $proof = $this->normalizeProof(
            $this->proofProvider->getProof($order)
        );
        $originalOrder = $this->freshOrderLoader->execute($originalOrderId);
        $this->validator->execute(
            $state['exchange'],
            $order,
            $originalOrder,
            $state['replacement_rows'],
            $state['document_rows'],
            $state['settlement_rows'],
            $intentHash
        );

        return $this->writer->execute(
            $state['exchange'],
            $state['row'],
            $state['return_rows'],
            $state['replacement_rows'],
            $state['settlement_rows'],
            $proof
        );
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
                __('The delivery exchange identity changed while locking.')
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

    private function normalizeProof(?string $proof): string
    {
        if ($proof === null) {
            throw new InvariantViolationException(
                __(
                    'No trusted replacement delivery proof is available. '
                    . 'Configure a deployment delivery-proof adapter.'
                )
            );
        }
        $proof = trim($proof);
        if (!preg_match(
            '/^[A-Za-z0-9][A-Za-z0-9:._\\/-]{0,127}$/D',
            $proof
        )) {
            throw new InvariantViolationException(
                __('The trusted replacement delivery proof is invalid.')
            );
        }

        return $proof;
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
                __('The delivery replacement order identity changed while locking.')
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
                __('The replacement delivery order lock identity is invalid.')
            );
        }
    }
}
