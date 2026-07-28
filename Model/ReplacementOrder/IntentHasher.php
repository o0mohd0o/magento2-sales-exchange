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
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * Fingerprint only the immutable approved replacement-order intent.
 */
class IntentHasher
{
    private EligibilityValidator $eligibilityValidator;

    private DecimalMath $moneyMath;

    private DecimalMath $quantityMath;

    private SerializerInterface $serializer;

    public function __construct(
        EligibilityValidator $eligibilityValidator,
        DecimalMath $moneyMath,
        DecimalMath $quantityMath,
        SerializerInterface $serializer
    ) {
        $this->eligibilityValidator = $eligibilityValidator;
        $this->moneyMath = $moneyMath;
        $this->quantityMath = $quantityMath;
        $this->serializer = $serializer;
    }

    /**
     * Mutable workflow status, aggregate versions, actor, and comments are
     * deliberately excluded so a post-commit retry has the same identity.
     *
     * @param array<int, array<string, mixed>> $replacementRows
     */
    public function execute(
        ExchangeInterface $exchange,
        array $replacementRows
    ): string {
        $this->eligibilityValidator->assertSnapshot(
            $exchange,
            $replacementRows
        );
        usort(
            $replacementRows,
            static fn (array $left, array $right): int =>
                (int)$left[ReplacementItemInterface::ENTITY_ID]
                    <=> (int)$right[ReplacementItemInterface::ENTITY_ID]
        );

        $items = [];
        foreach ($replacementRows as $row) {
            $items[] = [
                'entity_id' => (int)$row[ReplacementItemInterface::ENTITY_ID],
                'product_id' => (int)$row[ReplacementItemInterface::PRODUCT_ID],
                'sku' => (string)$row[ReplacementItemInterface::SKU],
                'name' => (string)$row[ReplacementItemInterface::NAME],
                'qty' => $this->quantityMath->normalize(
                    (string)$row[ReplacementItemInterface::QTY]
                ),
                'unit_price_amount' => $this->moneyMath->normalize(
                    (string)$row[ReplacementItemInterface::UNIT_PRICE_AMOUNT]
                ),
                'row_total_amount' => $this->moneyMath->normalize(
                    (string)$row[ReplacementItemInterface::ROW_TOTAL_AMOUNT]
                ),
                'product_options_json' => null,
            ];
        }

        $snapshot = [
            'exchange' => [
                'entity_id' => $exchange->getEntityId(),
                'increment_id' => $exchange->getIncrementId(),
                'original_order_id' => $exchange->getOriginalOrderId(),
                'store_id' => $exchange->getStoreId(),
                'customer_id' => $exchange->getCustomerId(),
                'currency_code' => $exchange->getCurrencyCode(),
                'base_currency_code' => $exchange->getBaseCurrencyCode(),
                'replacement_amount' => $this->moneyMath->normalize(
                    $exchange->getReplacementAmount()
                ),
                'shipping_amount' => $this->moneyMath->normalize(
                    $exchange->getShippingAmount()
                ),
                'fee_amount' => $this->moneyMath->normalize(
                    $exchange->getFeeAmount()
                ),
            ],
            'replacement_items' => $items,
        ];

        return hash('sha256', $this->serializer->serialize($snapshot));
    }
}
