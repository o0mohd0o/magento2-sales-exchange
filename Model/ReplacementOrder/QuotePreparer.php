<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\ReplacementOrder;

use Bonlineco\SalesExchange\Api\Data\ExchangeInterface;
use Bonlineco\SalesExchange\Api\Data\ReplacementItemInterface;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Carrier\Replacement as ReplacementCarrier;
use Bonlineco\SalesExchange\Model\Payment\Replacement as ReplacementPayment;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Directory\Model\CurrencyFactory;
use Magento\Framework\DataObjectFactory;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\Quote\Item;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Build or recover one inactive, isolated, precisely priced replacement quote.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
 */
class QuotePreparer
{
    private EligibilityValidator $eligibilityValidator;

    private PreparedQuoteLookup $preparedQuoteLookup;

    private QuoteFactory $quoteFactory;

    private CartRepositoryInterface $quoteRepository;

    private CurrencyFactory $currencyFactory;

    private ProductRepositoryInterface $productRepository;

    private DataObjectFactory $dataObjectFactory;

    private AddressSnapshotCopier $addressSnapshotCopier;

    private QuoteValidator $quoteValidator;

    private ExecutionContext $executionContext;

    public function __construct(
        EligibilityValidator $eligibilityValidator,
        PreparedQuoteLookup $preparedQuoteLookup,
        QuoteFactory $quoteFactory,
        CartRepositoryInterface $quoteRepository,
        CurrencyFactory $currencyFactory,
        ProductRepositoryInterface $productRepository,
        DataObjectFactory $dataObjectFactory,
        AddressSnapshotCopier $addressSnapshotCopier,
        QuoteValidator $quoteValidator,
        ExecutionContext $executionContext
    ) {
        $this->eligibilityValidator = $eligibilityValidator;
        $this->preparedQuoteLookup = $preparedQuoteLookup;
        $this->quoteFactory = $quoteFactory;
        $this->quoteRepository = $quoteRepository;
        $this->currencyFactory = $currencyFactory;
        $this->productRepository = $productRepository;
        $this->dataObjectFactory = $dataObjectFactory;
        $this->addressSnapshotCopier = $addressSnapshotCopier;
        $this->quoteValidator = $quoteValidator;
        $this->executionContext = $executionContext;
    }

    /**
     * @param array<int, array<string, mixed>> $replacementRows
     */
    public function execute(
        OrderInterface $originalOrder,
        ExchangeInterface $exchange,
        array $replacementRows,
        string $intentHash
    ): Quote {
        $this->eligibilityValidator->execute($exchange, $replacementRows);
        $this->assertOrderSnapshots($originalOrder, $exchange);
        $existing = $this->preparedQuoteLookup->find(
            (int)$exchange->getEntityId(),
            $intentHash
        );

        /** @var Quote $quote */
        $quote = $this->executionContext->execute(
            (int)$exchange->getEntityId(),
            $intentHash,
            function () use (
                $existing,
                $originalOrder,
                $exchange,
                $replacementRows,
                $intentHash
            ): Quote {
                if ($existing !== null) {
                    $this->executionContext->markQuote($existing);
                    $this->quoteValidator->assertPrepared(
                        $existing,
                        $originalOrder,
                        $exchange,
                        $replacementRows,
                        $intentHash
                    );

                    return $existing;
                }

                return $this->create(
                    $originalOrder,
                    $exchange,
                    $replacementRows,
                    $intentHash
                );
            }
        );

        return $quote;
    }

    /**
     * @param array<int, array<string, mixed>> $replacementRows
     */
    private function create(
        OrderInterface $originalOrder,
        ExchangeInterface $exchange,
        array $replacementRows,
        string $intentHash
    ): Quote {
        $quote = $this->quoteFactory->create();
        if (!$quote instanceof Quote) {
            throw new InvariantViolationException(
                __('The native quote implementation is not supported.')
            );
        }
        $quote->setStoreId((int)$exchange->getStoreId())
            ->setIsActive(false)
            ->setData(Marker::EXCHANGE_ID, $exchange->getEntityId())
            ->setData(Marker::INTENT_HASH, $intentHash);
        $this->executionContext->markQuote($quote);
        $this->configureCurrency($quote, $exchange);
        $this->copyCustomer($quote, $originalOrder);
        $this->addItems($quote, $exchange, $replacementRows);
        $this->addressSnapshotCopier->execute($originalOrder, $quote);

        $shippingMethod = ReplacementCarrier::CARRIER_CODE
            . '_'
            . ReplacementCarrier::METHOD_CODE;
        $shippingAddress = $quote->getShippingAddress();
        $shippingAddress->setCollectShippingRates(true)
            ->collectShippingRates()
            ->setShippingMethod($shippingMethod);
        if ($shippingAddress->getShippingRateByCode($shippingMethod) === false) {
            throw new InvariantViolationException(
                __('The trusted replacement delivery rate is unavailable.')
            );
        }
        $quote->getPayment()->importData([
            'method' => ReplacementPayment::CODE,
        ]);
        $quote->setCouponCode(null)
            ->setAppliedRuleIds(null)
            ->setTotalsCollectedFlag(false)
            ->collectTotals()
            ->setIsActive(false);
        $this->quoteValidator->assertPrepared(
            $quote,
            $originalOrder,
            $exchange,
            $replacementRows,
            $intentHash
        );
        $this->quoteRepository->save($quote);
        if ((int)$quote->getId() <= 0 || (bool)$quote->getIsActive()) {
            throw new InvariantViolationException(
                __('Magento did not persist the isolated replacement quote.')
            );
        }
        $this->quoteValidator->assertPrepared(
            $quote,
            $originalOrder,
            $exchange,
            $replacementRows,
            $intentHash
        );

        return $quote;
    }

    private function configureCurrency(
        Quote $quote,
        ExchangeInterface $exchange
    ): void {
        $baseCurrencyCode = (string)$quote->getStore()->getBaseCurrencyCode();
        if ($baseCurrencyCode !== $exchange->getBaseCurrencyCode()) {
            throw new InvariantViolationException(
                __('The store base currency changed after the exchange was approved.')
            );
        }
        $currency = $this->currencyFactory->create();
        $currency->load($exchange->getCurrencyCode());
        if ((string)$currency->getCode() !== $exchange->getCurrencyCode()) {
            throw new InvariantViolationException(
                __('The original order currency is no longer available.')
            );
        }
        $quote->setForcedCurrency($currency)
            ->setBaseCurrencyCode($exchange->getBaseCurrencyCode())
            ->setStoreCurrencyCode($exchange->getBaseCurrencyCode())
            ->setQuoteCurrencyCode($exchange->getCurrencyCode());
    }

    private function copyCustomer(
        Quote $quote,
        OrderInterface $originalOrder
    ): void {
        $quote->setCustomerId($originalOrder->getCustomerId())
            ->setCustomerIsGuest((bool)$originalOrder->getCustomerIsGuest())
            ->setCustomerGroupId((int)$originalOrder->getCustomerGroupId())
            ->setCustomerEmail((string)$originalOrder->getCustomerEmail())
            ->setCustomerPrefix($originalOrder->getCustomerPrefix())
            ->setCustomerFirstname((string)$originalOrder->getCustomerFirstname())
            ->setCustomerMiddlename($originalOrder->getCustomerMiddlename())
            ->setCustomerLastname((string)$originalOrder->getCustomerLastname())
            ->setCustomerSuffix($originalOrder->getCustomerSuffix())
            ->setCustomerDob($originalOrder->getCustomerDob())
            ->setCustomerGender($originalOrder->getCustomerGender())
            ->setCustomerTaxvat($originalOrder->getCustomerTaxvat())
            ->setCheckoutMethod(
                $originalOrder->getCustomerIsGuest()
                    ? 'guest'
                    : Quote::CHECKOUT_METHOD_LOGIN_IN
            )
            ->setCustomerNoteNotify(false);
    }

    /**
     * @param array<int, array<string, mixed>> $replacementRows
     */
    private function addItems(
        Quote $quote,
        ExchangeInterface $exchange,
        array $replacementRows
    ): void {
        usort(
            $replacementRows,
            static fn (array $left, array $right): int =>
                (int)$left[ReplacementItemInterface::ENTITY_ID]
                    <=> (int)$right[ReplacementItemInterface::ENTITY_ID]
        );
        foreach ($replacementRows as $row) {
            $product = $this->productRepository->getById(
                (int)$row[ReplacementItemInterface::PRODUCT_ID],
                false,
                (int)$exchange->getStoreId(),
                true
            );
            $this->assertProduct($product, $row);
            $replacementItemId = (int)$row[ReplacementItemInterface::ENTITY_ID];
            // This internal option prevents two approved rows for the same SKU
            // from being merged before their durable item markers are saved.
            $product->addCustomOption(
                Marker::REPLACEMENT_ITEM_ID,
                (string)$replacementItemId
            );
            $request = $this->dataObjectFactory->create([
                'data' => [
                    'qty' => (string)$row[ReplacementItemInterface::QTY],
                    'custom_price' => (string)$row[
                        ReplacementItemInterface::UNIT_PRICE_AMOUNT
                    ],
                    'original_custom_price' => (string)$row[
                        ReplacementItemInterface::UNIT_PRICE_AMOUNT
                    ],
                ],
            ]);
            $item = $quote->addProduct($product, $request);
            if (!$item instanceof Item) {
                throw new InvariantViolationException(
                    __('Replacement SKU "%1" could not be added to the native quote.', $product->getSku())
                );
            }
            $item->setData(Marker::REPLACEMENT_ITEM_ID, $replacementItemId)
                ->setName((string)$row[ReplacementItemInterface::NAME])
                ->setCustomPrice(
                    (string)$row[ReplacementItemInterface::UNIT_PRICE_AMOUNT]
                )
                ->setOriginalCustomPrice(
                    (string)$row[ReplacementItemInterface::UNIT_PRICE_AMOUNT]
                )
                ->setNoDiscount(true)
                ->setAppliedRuleIds(null);
        }
    }

    /**
     * @param mixed $product
     * @param array<string, mixed> $row
     */
    private function assertProduct($product, array $row): void
    {
        if (!$product instanceof Product
            || (int)$product->getId()
                !== (int)$row[ReplacementItemInterface::PRODUCT_ID]
            || (string)$product->getSku()
                !== (string)$row[ReplacementItemInterface::SKU]
            || (int)$product->getStatus() !== Status::STATUS_ENABLED
            || (string)$product->getTypeId() !== Type::TYPE_SIMPLE
            || $product->getIsVirtual()
            || $product->getHasOptions()
            || !$product->isSalable()
        ) {
            throw new InvariantViolationException(
                __('A replacement product is no longer an enabled, salable, physical simple product.')
            );
        }
    }

    private function assertOrderSnapshots(
        OrderInterface $order,
        ExchangeInterface $exchange
    ): void {
        $customerId = $order->getCustomerId();
        $customerId = $customerId === null ? null : (int)$customerId;
        if ((int)$order->getEntityId() !== $exchange->getOriginalOrderId()
            || (int)$order->getStoreId() !== $exchange->getStoreId()
            || $customerId !== $exchange->getCustomerId()
            || (string)$order->getOrderCurrencyCode()
                !== $exchange->getCurrencyCode()
            || (string)$order->getBaseCurrencyCode()
                !== $exchange->getBaseCurrencyCode()
            || trim((string)$order->getCustomerEmail()) === ''
        ) {
            throw new InvariantViolationException(
                __('The original order no longer matches the frozen exchange snapshots.')
            );
        }
    }
}
