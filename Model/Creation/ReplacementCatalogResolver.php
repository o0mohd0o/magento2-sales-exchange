<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\Creation;

use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Bonlineco\SalesExchange\Model\ReplacementCurrencyCalculator;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Resolve a simple replacement SKU and its order-currency price estimate.
 */
class ReplacementCatalogResolver
{
    private ProductRepositoryInterface $productRepository;

    private ReplacementCurrencyCalculator $replacementCurrencyCalculator;

    private DecimalMath $moneyMath;

    public function __construct(
        ProductRepositoryInterface $productRepository,
        ReplacementCurrencyCalculator $replacementCurrencyCalculator,
        DecimalMath $moneyMath
    ) {
        $this->productRepository = $productRepository;
        $this->replacementCurrencyCalculator = $replacementCurrencyCalculator;
        $this->moneyMath = $moneyMath;
    }

    public function execute(string $sku, OrderInterface $order): ReplacementSnapshot
    {
        try {
            $product = $this->productRepository->get(
                trim($sku),
                false,
                (int)$order->getStoreId(),
                true
            );
        } catch (NoSuchEntityException $exception) {
            throw new InvariantViolationException(
                __('Replacement SKU "%1" does not exist.', $sku),
                $exception
            );
        }
        if ((int)$product->getStatus() !== Status::STATUS_ENABLED) {
            throw new InvariantViolationException(
                __('Replacement SKU "%1" is disabled.', $product->getSku())
            );
        }
        if ((string)$product->getTypeId() !== 'simple') {
            throw new InvariantViolationException(
                __('Replacement SKU "%1" must be a simple product in this release.', $product->getSku())
            );
        }
        if ((int)$product->getId() <= 0 || trim((string)$product->getName()) === '') {
            throw new InvariantViolationException(
                __('Replacement SKU "%1" has incomplete catalog data.', $product->getSku())
            );
        }

        $basePrice = $this->moneyMath->assertNonNegative(
            (string)$product->getPrice(),
            'Replacement catalog price'
        );
        $persistedRate = $order->getBaseToOrderRate();
        $rate = $persistedRate === null
            || trim((string)$persistedRate) === ''
                ? '1'
                : (string)$persistedRate;
        $orderCurrencyPrice = $this->replacementCurrencyCalculator->convertUnit(
            $basePrice,
            $rate
        );

        return new ReplacementSnapshot(
            (int)$product->getId(),
            (string)$product->getSku(),
            (string)$product->getName(),
            $orderCurrencyPrice
        );
    }
}
