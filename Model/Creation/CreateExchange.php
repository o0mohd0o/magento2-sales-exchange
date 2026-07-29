<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\Creation;

use Bonlineco\SalesExchange\Api\AllocationValidatorInterface;
use Bonlineco\SalesExchange\Api\ConfigInterface;
use Bonlineco\SalesExchange\Api\CreateExchangeInterface;
use Bonlineco\SalesExchange\Api\Data\CreateExchangeRequestInterface;
use Bonlineco\SalesExchange\Api\Data\ExchangeInterface;
use Bonlineco\SalesExchange\Api\Data\ReplacementSelectionInterface;
use Bonlineco\SalesExchange\Api\Data\ReturnSelectionInterface;
use Bonlineco\SalesExchange\Api\ExchangeEligibilityInterface;
use Bonlineco\SalesExchange\Api\History\ActorType;
use Bonlineco\SalesExchange\Api\Status\ExchangeStatus;
use Bonlineco\SalesExchange\Api\Status\ReplacementStatus;
use Bonlineco\SalesExchange\Api\Status\ReturnStatus;
use Bonlineco\SalesExchange\Api\Status\SettlementStatus;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Exchange;
use Bonlineco\SalesExchange\Model\ExchangeFactory;
use Bonlineco\SalesExchange\Model\HistoryFactory;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Bonlineco\SalesExchange\Model\OrderItemRemainingQuantity;
use Bonlineco\SalesExchange\Model\ReplacementCurrencyCalculator;
use Bonlineco\SalesExchange\Model\ReplacementItemFactory;
use Bonlineco\SalesExchange\Model\ResourceModel\AllocationGuard;
use Bonlineco\SalesExchange\Model\ResourceModel\Exchange as ExchangeResource;
use Bonlineco\SalesExchange\Model\ResourceModel\History as HistoryResource;
use Bonlineco\SalesExchange\Model\ResourceModel\ReplacementItem as ReplacementItemResource;
use Bonlineco\SalesExchange\Model\ResourceModel\ReturnItem as ReturnItemResource;
use Bonlineco\SalesExchange\Model\ReturnItemFactory;
use Bonlineco\SalesExchange\Model\ReturnableOrderItemValidator;
use Bonlineco\SalesExchange\Model\VersionGuard;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Math\Random;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Model\OrderMutexInterface;
use Magento\Tax\Model\Config as TaxConfig;

/**
 * Single transactional writer for a new draft exchange aggregate.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class CreateExchange implements CreateExchangeInterface
{
    private ExchangeEligibilityInterface $exchangeEligibility;

    private ConfigInterface $config;

    private CreateInputValidator $inputValidator;

    private ExchangeFactory $exchangeFactory;

    private ExchangeResource $exchangeResource;

    private ReturnItemFactory $returnItemFactory;

    private ReturnItemResource $returnItemResource;

    private ReplacementItemFactory $replacementItemFactory;

    private ReplacementItemResource $replacementItemResource;

    private HistoryFactory $historyFactory;

    private HistoryResource $historyResource;

    private AllocationGuard $allocationGuard;

    private OrderItemRemainingQuantity $orderItemRemainingQuantity;

    private AllocationValidatorInterface $allocationValidator;

    private ReturnableOrderItemValidator $returnableOrderItemValidator;

    private OrderItemCreditCalculator $creditCalculator;

    private ReplacementCatalogResolver $replacementCatalogResolver;

    private ReplacementCurrencyCalculator $replacementCurrencyCalculator;

    private DecimalMath $quantityMath;

    private Random $random;

    private OrderMutexInterface $orderMutex;

    private TaxConfig $taxConfig;

    public function __construct(
        ExchangeEligibilityInterface $exchangeEligibility,
        ConfigInterface $config,
        CreateInputValidator $inputValidator,
        ExchangeFactory $exchangeFactory,
        ExchangeResource $exchangeResource,
        ReturnItemFactory $returnItemFactory,
        ReturnItemResource $returnItemResource,
        ReplacementItemFactory $replacementItemFactory,
        ReplacementItemResource $replacementItemResource,
        HistoryFactory $historyFactory,
        HistoryResource $historyResource,
        AllocationGuard $allocationGuard,
        OrderItemRemainingQuantity $orderItemRemainingQuantity,
        AllocationValidatorInterface $allocationValidator,
        ReturnableOrderItemValidator $returnableOrderItemValidator,
        OrderItemCreditCalculator $creditCalculator,
        ReplacementCatalogResolver $replacementCatalogResolver,
        ReplacementCurrencyCalculator $replacementCurrencyCalculator,
        DecimalMath $quantityMath,
        Random $random,
        OrderMutexInterface $orderMutex,
        TaxConfig $taxConfig
    ) {
        $this->exchangeEligibility = $exchangeEligibility;
        $this->config = $config;
        $this->inputValidator = $inputValidator;
        $this->exchangeFactory = $exchangeFactory;
        $this->exchangeResource = $exchangeResource;
        $this->returnItemFactory = $returnItemFactory;
        $this->returnItemResource = $returnItemResource;
        $this->replacementItemFactory = $replacementItemFactory;
        $this->replacementItemResource = $replacementItemResource;
        $this->historyFactory = $historyFactory;
        $this->historyResource = $historyResource;
        $this->allocationGuard = $allocationGuard;
        $this->orderItemRemainingQuantity = $orderItemRemainingQuantity;
        $this->allocationValidator = $allocationValidator;
        $this->returnableOrderItemValidator = $returnableOrderItemValidator;
        $this->creditCalculator = $creditCalculator;
        $this->replacementCatalogResolver = $replacementCatalogResolver;
        $this->replacementCurrencyCalculator = $replacementCurrencyCalculator;
        $this->quantityMath = $quantityMath;
        $this->random = $random;
        $this->orderMutex = $orderMutex;
        $this->taxConfig = $taxConfig;
    }

    public function execute(CreateExchangeRequestInterface $request): ExchangeInterface
    {
        $orderId = $request->getOrderId();
        if ($orderId <= 0) {
            throw new InvariantViolationException(
                __('A valid original order is required.')
            );
        }
        try {
            /** @var ExchangeInterface $exchange */
            $exchange = $this->orderMutex->execute(
                $orderId,
                \Closure::fromCallable([$this, 'executeLocked']),
                [$request]
            );

            return $exchange;
        } catch (\Throwable $exception) {
            if ($exception instanceof LocalizedException) {
                throw $exception;
            }
            throw new CouldNotSaveException(
                __('The draft exchange could not be created.'),
                $exception
            );
        }
    }

    /**
     * Resolve and persist only after Magento's sales-order row is locked.
     *
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
     */
    private function executeLocked(CreateExchangeRequestInterface $request): ExchangeInterface
    {
        $returnSelections = $this->sortReturnSelections(
            $request->getReturnItems()
        );
        $lastLockedOrderItemId = null;
        foreach ($returnSelections as $selection) {
            $orderItemId = $selection->getOrderItemId();
            if ($orderItemId <= 0) {
                throw new InvariantViolationException(
                    __('A valid original order item is required.')
                );
            }
            if ($orderItemId !== $lastLockedOrderItemId) {
                $this->allocationGuard->lock($orderItemId);
                $lastLockedOrderItemId = $orderItemId;
            }
        }

        // Eligibility is deliberately the first consistent order read after
        // every requested allocation row has been acquired.
        $order = $this->exchangeEligibility->execute($request->getOrderId());
        $storeId = $order->getStoreId() === null ? null : (int)$order->getStoreId();
        $this->inputValidator->execute(
            $request,
            $this->config->getAllowedReasonCodes($storeId)
        );
        $orderItems = $this->indexOrderItems($order);
        foreach ($returnSelections as $selection) {
            $orderItemId = $selection->getOrderItemId();
            if (!isset($orderItems[$orderItemId])) {
                throw new InvariantViolationException(
                    __('The selected original order item does not belong to this order.')
                );
            }
            $this->returnableOrderItemValidator->execute($orderItems[$orderItemId]);
        }

        $exchange = $this->createCase($request, $order);
        $this->exchangeResource->save($exchange);
        $exchangeId = (int)$exchange->getEntityId();
        foreach ($returnSelections as $selection) {
            $this->createReturnItem($exchangeId, $selection, $orderItems);
        }
        foreach ($this->sortReplacementSelections($request->getReplacementItems()) as $selection) {
            $this->createReplacementItem($exchangeId, $selection, $order);
        }
        $this->recordCreatedHistory($exchangeId, $request->getActorId());

        return $exchange;
    }

    private function createCase(
        CreateExchangeRequestInterface $request,
        OrderInterface $order
    ): Exchange {
        $exchange = $this->exchangeFactory->create();
        $customerId = $order->getCustomerId();
        $exchange->setIncrementId(
            'EX-' . $this->random->getRandomString(
                16,
                Random::CHARS_UPPERS . Random::CHARS_DIGITS
            )
        )->setOriginalOrderId((int)$order->getEntityId())
            ->setStoreId($order->getStoreId() === null ? null : (int)$order->getStoreId())
            ->setCustomerId($customerId === null ? null : (int)$customerId)
            ->setCurrencyCode((string)$order->getOrderCurrencyCode())
            ->setBaseCurrencyCode((string)$order->getBaseCurrencyCode())
            ->setCatalogPricesIncludeTax(
                $this->taxConfig->priceIncludesTax($order->getStoreId())
            )
            ->setExchangeStatus(ExchangeStatus::DRAFT)
            ->setReturnStatus(ReturnStatus::PENDING)
            ->setReplacementStatus(ReplacementStatus::PENDING)
            ->setSettlementStatus(SettlementStatus::PENDING)
            ->setReturnCreditAmount('0.0000')
            ->setNativeReturnCreditAmount('0.0000')
            ->setBaseNativeReturnCreditAmount('0.0000')
            ->setNativeReplacementAmount('0.0000')
            ->setBaseNativeReplacementAmount('0.0000')
            ->setReplacementAmount('0.0000')
            ->setShippingAmount('0.0000')
            ->setFeeAmount('0.0000')
            ->setBalanceAmount('0.0000')
            ->setCustomerNote($this->normalizeNote($request->getCustomerNote()))
            ->setInternalNote($this->normalizeNote($request->getInternalNote()))
            ->setVersion(VersionGuard::INITIAL_VERSION);

        return $exchange;
    }

    /**
     * @param array<int, OrderItemInterface> $orderItems
     */
    private function createReturnItem(
        int $exchangeId,
        ReturnSelectionInterface $selection,
        array $orderItems
    ): void {
        $orderItemId = $selection->getOrderItemId();
        if (!isset($orderItems[$orderItemId])) {
            throw new InvariantViolationException(
                __('The selected original order item does not belong to this order.')
            );
        }
        $orderItem = $orderItems[$orderItemId];
        $this->returnableOrderItemValidator->execute($orderItem);
        $remaining = $this->orderItemRemainingQuantity->execute(
            $orderItem,
            $orderItems
        );
        $quantity = $this->quantityMath->normalize($selection->getQuantity());
        $this->allocationValidator->execute($quantity, $remaining);

        $item = $this->returnItemFactory->create();
        $item->setExchangeId($exchangeId)
            ->setOrderItemId($orderItemId)
            ->setSku((string)$orderItem->getSku())
            ->setName((string)$orderItem->getName())
            ->setRequestedQty($quantity)
            ->setAllocatedQty($quantity)
            ->setReceivedQty('0.0000')
            ->setReceiptResolved(false)
            ->setAcceptedQty('0.0000')
            ->setCreditedQty('0.0000')
            ->setRejectedQty('0.0000')
            ->setUnitCreditAmount($this->creditCalculator->execute($orderItem))
            ->setRowCreditAmount('0.0000')
            ->setReasonCode($selection->getReasonCode())
            ->setConditionCode(null)
            ->setDisposition(null)
            ->setVersion(VersionGuard::INITIAL_VERSION);
        $this->returnItemResource->save($item);
    }

    private function createReplacementItem(
        int $exchangeId,
        ReplacementSelectionInterface $selection,
        OrderInterface $order
    ): void {
        $snapshot = $this->replacementCatalogResolver->execute($selection->getSku(), $order);
        $quantity = $this->quantityMath->normalize($selection->getQuantity());
        $rowTotal = $this->replacementCurrencyCalculator->execute(
            $quantity,
            $snapshot->getUnitPrice()
        );
        $item = $this->replacementItemFactory->create();
        $item->setExchangeId($exchangeId)
            ->setProductId($snapshot->getProductId())
            ->setSku($snapshot->getSku())
            ->setName($snapshot->getName())
            ->setQty($quantity)
            ->setUnitPriceAmount($snapshot->getUnitPrice())
            ->setRowTotalAmount($rowTotal)
            ->setProductOptionsJson(null)
            ->setReplacementOrderItemId(null)
            ->setVersion(VersionGuard::INITIAL_VERSION);
        $this->replacementItemResource->save($item);
    }

    private function recordCreatedHistory(int $exchangeId, int $actorId): void
    {
        $history = $this->historyFactory->create();
        $history->setExchangeId($exchangeId)
            ->setAction('created')
            ->setStatusDimension(null)
            ->setFromValue(null)
            ->setToValue(ExchangeStatus::DRAFT)
            ->setActorType(ActorType::ADMIN)
            ->setActorId($actorId)
            ->setComment(null);
        $this->historyResource->save($history);
    }

    /**
     * @return array<int, OrderItemInterface>
     */
    private function indexOrderItems(OrderInterface $order): array
    {
        $indexed = [];
        foreach ((array)$order->getItems() as $item) {
            if ($item instanceof OrderItemInterface && (int)$item->getItemId() > 0) {
                $indexed[(int)$item->getItemId()] = $item;
            }
        }

        return $indexed;
    }

    /**
     * @param ReturnSelectionInterface[] $selections
     * @return ReturnSelectionInterface[]
     */
    private function sortReturnSelections(array $selections): array
    {
        usort(
            $selections,
            static fn (
                ReturnSelectionInterface $left,
                ReturnSelectionInterface $right
            ): int => $left->getOrderItemId() <=> $right->getOrderItemId()
        );

        return $selections;
    }

    /**
     * @param ReplacementSelectionInterface[] $selections
     * @return ReplacementSelectionInterface[]
     */
    private function sortReplacementSelections(array $selections): array
    {
        usort(
            $selections,
            static fn (
                ReplacementSelectionInterface $left,
                ReplacementSelectionInterface $right
            ): int => strcmp(strtolower(trim($left->getSku())), strtolower(trim($right->getSku())))
        );

        return $selections;
    }

    private function normalizeNote(?string $note): ?string
    {
        if ($note === null) {
            return null;
        }
        $note = trim($note);

        return $note === '' ? null : $note;
    }
}
