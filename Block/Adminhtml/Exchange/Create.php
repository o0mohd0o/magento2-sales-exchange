<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Block\Adminhtml\Exchange;

use Bonlineco\SalesExchange\Api\ConfigInterface;
use Bonlineco\SalesExchange\Api\ReasonCode;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Creation\CreateFormData;
use Bonlineco\SalesExchange\Model\OrderItemRemainingQuantity;
use Bonlineco\SalesExchange\Model\Workflow\AdminActionMap;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Build the admin create form from an immutable original-order snapshot.
 */
class Create extends Template
{
    private const SUPPORTED_PRODUCT_TYPES = ['simple', 'configurable'];

    private OrderRepositoryInterface $orderRepository;

    private OrderItemRemainingQuantity $orderItemRemainingQuantity;

    private SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory;

    private PriceCurrencyInterface $priceCurrency;

    private Json $json;

    private ConfigInterface $config;

    private DataPersistorInterface $dataPersistor;

    private ?OrderInterface $order = null;

    private bool $orderLoaded = false;

    /**
     * @var array<int, array{item: OrderItemInterface, remaining_qty: string}>|null
     */
    private ?array $returnableItems = null;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $persistedFormData = null;

    /**
     * @param Context $context
     * @param OrderRepositoryInterface $orderRepository
     * @param OrderItemRemainingQuantity $orderItemRemainingQuantity
     * @param SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory
     * @param PriceCurrencyInterface $priceCurrency
     * @param Json $json
     * @param ConfigInterface $config
     * @param DataPersistorInterface $dataPersistor
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        OrderRepositoryInterface $orderRepository,
        OrderItemRemainingQuantity $orderItemRemainingQuantity,
        SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory,
        PriceCurrencyInterface $priceCurrency,
        Json $json,
        ConfigInterface $config,
        DataPersistorInterface $dataPersistor,
        array $data = []
    ) {
        $this->orderRepository = $orderRepository;
        $this->orderItemRemainingQuantity = $orderItemRemainingQuantity;
        $this->searchCriteriaBuilderFactory = $searchCriteriaBuilderFactory;
        $this->priceCurrency = $priceCurrency;
        $this->json = $json;
        $this->config = $config;
        $this->dataPersistor = $dataPersistor;
        parent::__construct($context, $data);
    }

    public function getOrder(): ?OrderInterface
    {
        if (!$this->canCreate()) {
            return null;
        }
        if ($this->orderLoaded) {
            return $this->order;
        }

        $this->orderLoaded = true;
        $orderId = (int)$this->getRequest()->getParam('order_id');
        if ($orderId > 0) {
            try {
                $this->order = $this->orderRepository->get($orderId);
                return $this->order;
            } catch (NoSuchEntityException $exception) {
                $this->order = null;
            }
        }

        $incrementId = trim((string)$this->getRequest()->getParam('order_increment_id'));
        if ($incrementId === '') {
            return null;
        }

        /** @var SearchCriteriaBuilder $builder */
        $builder = $this->searchCriteriaBuilderFactory->create();
        $orders = $this->orderRepository->getList(
            $builder
                ->addFilter(OrderInterface::INCREMENT_ID, $incrementId)
                ->setPageSize(1)
                ->create()
        )->getItems();
        $this->order = $orders === [] ? null : reset($orders);

        return $this->order;
    }

    /**
     * Return visible simple/configurable lines with exact remaining allocation.
     *
     * @return array<int, array{item: OrderItemInterface, remaining_qty: string}>
     */
    public function getReturnableItems(): array
    {
        if ($this->returnableItems !== null) {
            return $this->returnableItems;
        }

        $order = $this->getOrder();
        if ($order === null) {
            return $this->returnableItems = [];
        }

        /** @var array<int, OrderItemInterface> $orderItems */
        $orderItems = $order->getItems() ?? [];
        $visibleItems = [];
        foreach ($orderItems as $item) {
            if ((int)$item->getParentItemId() > 0
                || !in_array((string)$item->getProductType(), self::SUPPORTED_PRODUCT_TYPES, true)
            ) {
                continue;
            }
            $visibleItems[(int)$item->getItemId()] = $item;
        }

        $rows = [];
        foreach ($visibleItems as $itemId => $item) {
            try {
                $remaining = $this->orderItemRemainingQuantity->execute(
                    $item,
                    $orderItems
                );
            } catch (InvariantViolationException $exception) {
                continue;
            }

            if (bccomp($remaining, '0', 4) <= 0) {
                continue;
            }
            $rows[] = [
                'item' => $item,
                'remaining_qty' => $remaining,
            ];
        }

        return $this->returnableItems = $rows;
    }

    public function getSelectedOrderIncrementId(): string
    {
        $order = $this->getOrder();
        if ($order !== null) {
            return (string)$order->getIncrementId();
        }

        return trim((string)$this->getRequest()->getParam('order_increment_id'));
    }

    public function getOrderSelectionUrl(): string
    {
        return $this->getUrl('salesexchange/exchange/new');
    }

    public function getSaveUrl(): string
    {
        return $this->getUrl('salesexchange/exchange/save');
    }

    public function getBackUrl(): string
    {
        return $this->getUrl('salesexchange/exchange/index');
    }

    public function formatMoney(string $amount): string
    {
        $order = $this->getOrder();
        if ($order === null) {
            return $amount;
        }

        return $this->priceCurrency->format(
            $amount,
            false,
            PriceCurrencyInterface::DEFAULT_PRECISION,
            $order->getStoreId(),
            $order->getOrderCurrencyCode()
        );
    }

    public function formatDateTime(?string $date): string
    {
        if ($date === null || $date === '') {
            return (string)__('Not available');
        }

        return $this->_localeDate->formatDateTime(
            $date,
            \IntlDateFormatter::MEDIUM,
            \IntlDateFormatter::SHORT
        );
    }

    /**
     * @return array<string, Phrase>
     */
    public function getReasonOptions(): array
    {
        $labels = [
            'wrong_item' => __('Wrong Product'),
            'damaged' => __('Arrived Damaged'),
            'defective' => __('Defective'),
            'size_or_fit' => __('Size / Fit Issue'),
            'changed_mind' => __('Customer Changed Mind'),
            'other' => __('Other'),
        ];
        $order = $this->getOrder();
        $storeId = $order === null || $order->getStoreId() === null
            ? null
            : (int)$order->getStoreId();
        $allowed = array_intersect(
            $this->config->getAllowedReasonCodes($storeId),
            ReasonCode::all()
        );

        return array_intersect_key($labels, array_fill_keys($allowed, true));
    }

    public function getCreateJsConfig(): string
    {
        return $this->json->serialize([
            'Bonlineco_SalesExchange/js/exchange-create' => [],
        ]);
    }

    public function getValidationJsConfig(): string
    {
        return $this->json->serialize([
            'validation' => [],
        ]);
    }

    public function getRequiredValidationJson(): string
    {
        return $this->json->serialize([
            'required-entry' => true,
        ]);
    }

    public function getQuantityValidationJson(): string
    {
        return $this->json->serialize([
            'required-entry' => true,
            'validate-number' => true,
            'validate-greater-than-zero' => true,
        ]);
    }

    public function canCreate(): bool
    {
        return $this->_authorization->isAllowed(AdminActionMap::ACL_CREATE)
            && $this->_authorization->isAllowed(AdminActionMap::ACL_SALES_ORDER_VIEW);
    }

    /**
     * @return array{selected: bool, qty: string, reason_code: string}
     */
    public function getReturnFormData(int $itemId): array
    {
        $rows = $this->getPersistedFormData()['return_items'] ?? [];
        $row = is_array($rows) && isset($rows[$itemId]) && is_array($rows[$itemId])
            ? $rows[$itemId]
            : [];

        return [
            'selected' => (string)($row['selected'] ?? '0') === '1',
            'qty' => is_scalar($row['qty'] ?? null) ? (string)$row['qty'] : '1',
            'reason_code' => is_scalar($row['reason_code'] ?? null)
                ? (string)$row['reason_code']
                : '',
        ];
    }

    /**
     * @return array<int, array{sku: string, qty: string}>
     */
    public function getReplacementFormRows(): array
    {
        $rows = $this->getPersistedFormData()['replacement_items'] ?? [];
        $safeRows = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $safeRows[] = [
                    'sku' => is_scalar($row['sku'] ?? null) ? (string)$row['sku'] : '',
                    'qty' => is_scalar($row['qty'] ?? null) ? (string)$row['qty'] : '',
                ];
            }
        }

        return $safeRows === [] ? [['sku' => '', 'qty' => '1']] : $safeRows;
    }

    public function getPersistedNote(string $key): string
    {
        $value = $this->getPersistedFormData()[$key] ?? '';

        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function getPersistedFormData(): array
    {
        if ($this->persistedFormData !== null) {
            return $this->persistedFormData;
        }
        $orderId = (int)$this->getRequest()->getParam('order_id');
        $key = CreateFormData::getKey($orderId);
        $data = $this->dataPersistor->get($key);
        $this->dataPersistor->clear($key);
        if (!is_array($data) || (int)($data['order_id'] ?? 0) !== $orderId) {
            return $this->persistedFormData = [];
        }

        return $this->persistedFormData = $data;
    }
}
