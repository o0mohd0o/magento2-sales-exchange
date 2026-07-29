<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\ReplacementOrder;

use Bonlineco\SalesExchange\Api\Data\ExchangeInterface;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Order\FreshOrderLoader;
use Bonlineco\SalesExchange\Model\Payment\Replacement as ReplacementPayment;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\PaymentInterfaceFactory;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\ResourceModel\Quote as QuoteResource;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;

/**
 * Place one isolated quote through Magento's public checkout service.
 *
 * The active save required by CartManagementInterface and the resulting
 * inactive save are enclosed in one database transaction. Other requests
 * therefore never observe this quote as the customer's active storefront cart.
 */
class NativeOrderPlacer
{
    private ExecutionContext $executionContext;

    private QuoteValidator $quoteValidator;

    private CartManagementInterface $cartManagement;

    private CartRepositoryInterface $quoteRepository;

    private PaymentInterfaceFactory $paymentFactory;

    private QuoteResource $quoteResource;

    private OrderResource $orderResource;

    private FreshOrderLoader $freshOrderLoader;

    private ConvertedOrderValidator $convertedOrderValidator;

    private NativeOrderValidator $nativeOrderValidator;

    public function __construct(
        ExecutionContext $executionContext,
        QuoteValidator $quoteValidator,
        CartManagementInterface $cartManagement,
        CartRepositoryInterface $quoteRepository,
        PaymentInterfaceFactory $paymentFactory,
        QuoteResource $quoteResource,
        OrderResource $orderResource,
        FreshOrderLoader $freshOrderLoader,
        ConvertedOrderValidator $convertedOrderValidator,
        NativeOrderValidator $nativeOrderValidator
    ) {
        $this->executionContext = $executionContext;
        $this->quoteValidator = $quoteValidator;
        $this->cartManagement = $cartManagement;
        $this->quoteRepository = $quoteRepository;
        $this->paymentFactory = $paymentFactory;
        $this->quoteResource = $quoteResource;
        $this->orderResource = $orderResource;
        $this->freshOrderLoader = $freshOrderLoader;
        $this->convertedOrderValidator = $convertedOrderValidator;
        $this->nativeOrderValidator = $nativeOrderValidator;
    }

    /**
     * @param array<int, array<string, mixed>> $replacementRows
     */
    public function execute(
        Quote $quote,
        OrderInterface $originalOrder,
        ExchangeInterface $exchange,
        array $replacementRows,
        string $intentHash
    ): int {
        $this->quoteValidator->assertPrepared(
            $quote,
            $originalOrder,
            $exchange,
            $replacementRows,
            $intentHash
        );
        $quoteId = (int)$quote->getId();
        if ($quoteId <= 0) {
            throw new InvariantViolationException(
                __('The prepared replacement quote is not persisted.')
            );
        }

        return (int)$this->executionContext->execute(
            (int)$exchange->getEntityId(),
            $intentHash,
            function () use (
                $quote,
                $originalOrder,
                $exchange,
                $replacementRows,
                $quoteId,
                $intentHash
            ): int {
                $this->executionContext->setPreSubmitValidator(
                    function (Quote $candidate) use (
                        $originalOrder,
                        $exchange,
                        $replacementRows,
                        $intentHash
                    ): void {
                        $this->quoteValidator->assertPrepared(
                            $candidate,
                            $originalOrder,
                            $exchange,
                            $replacementRows,
                            $intentHash,
                            true
                        );
                    }
                );
                $this->executionContext->setPrePlaceOrderValidator(
                    function (
                        Quote $submittedQuote,
                        OrderInterface $candidate
                    ): void {
                        $this->convertedOrderValidator->execute(
                            $submittedQuote,
                            $candidate
                        );
                    }
                );
                $this->executionContext->setPostSaveOrderValidator(
                    function (OrderInterface $candidate) use (
                        $originalOrder,
                        $exchange,
                        $replacementRows,
                        $intentHash,
                        $quoteId
                    ): string {
                        $snapshot = $this->nativeOrderValidator->snapshot(
                            $candidate,
                            $originalOrder,
                            $exchange,
                            $replacementRows,
                            $intentHash,
                            $quoteId
                        );

                        return $snapshot['snapshot_hash'];
                    }
                );
                $this->executionContext->markQuote($quote);
                $payment = $this->paymentFactory->create();
                $payment->setMethod(ReplacementPayment::CODE);
                $connection = $this->quoteResource->getConnection();
                if ($connection !== $this->orderResource->getConnection()) {
                    throw new InvariantViolationException(
                        __('Replacement quotes and orders must share one database transaction.')
                    );
                }
                $connection->beginTransaction();
                try {
                    $quote->setIsActive(true);
                    $this->quoteRepository->save($quote);
                    $orderId = (int)$this->cartManagement->placeOrder(
                        (int)$quote->getId(),
                        $payment
                    );
                    $quote->setIsActive(false);
                    if ($orderId <= 0) {
                        throw new InvariantViolationException(
                            __('Magento did not return a native replacement order.')
                        );
                    }
                    $persistedOrder = $this->freshOrderLoader->execute(
                        $orderId
                    );
                    $this->executionContext->validateBeforeCommit(
                        $orderId,
                        $persistedOrder
                    );
                    $this->assertDurableInactiveQuote(
                        $quoteId,
                        (int)$exchange->getEntityId(),
                        $intentHash
                    );
                    $connection->commit();

                    return $orderId;
                } catch (\Throwable $exception) {
                    $quote->setIsActive(false);
                    $connection->rollBack();
                    throw $exception;
                } finally {
                    $quote->setIsActive(false);
                }
            },
            $replacementRows
        );
    }

    private function assertDurableInactiveQuote(
        int $quoteId,
        int $exchangeId,
        string $intentHash
    ): void {
        $connection = $this->quoteResource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from(
                    $this->quoteResource->getMainTable(),
                    [
                        'entity_id',
                        'is_active',
                        Marker::EXCHANGE_ID,
                        Marker::INTENT_HASH,
                    ]
                )
                ->where('entity_id = ?', $quoteId)
                ->limit(1)
        );
        if (!is_array($row)
            || (int)($row['entity_id'] ?? 0) !== $quoteId
            || (int)($row['is_active'] ?? 1) !== 0
            || (int)($row[Marker::EXCHANGE_ID] ?? 0) !== $exchangeId
            || !is_string($row[Marker::INTENT_HASH] ?? null)
            || !hash_equals(
                $intentHash,
                (string)$row[Marker::INTENT_HASH]
            )
        ) {
            throw new InvariantViolationException(
                __('Magento did not durably deactivate the replacement quote.')
            );
        }
    }
}
