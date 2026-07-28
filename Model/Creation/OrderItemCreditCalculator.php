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
use Magento\Sales\Api\Data\OrderItemInterface;

/**
 * Estimate original net-paid credit per ordered unit.
 */
class OrderItemCreditCalculator
{
    private DecimalMath $moneyMath;

    private DecimalMath $quantityMath;

    public function __construct(DecimalMath $moneyMath, DecimalMath $quantityMath)
    {
        $this->moneyMath = $moneyMath;
        $this->quantityMath = $quantityMath;
    }

    public function execute(OrderItemInterface $item): string
    {
        $quantity = $this->quantityMath->assertNonNegative(
            (string)$item->getQtyOrdered(),
            'Ordered quantity'
        );
        if ($this->quantityMath->compare($quantity, '0') <= 0) {
            throw new InvariantViolationException(
                __('The original order item has no ordered quantity.')
            );
        }

        $netPaid = $this->moneyMath->add(
            $this->moneyMath->add(
                (string)$item->getRowTotal(),
                (string)$item->getTaxAmount()
            ),
            (string)$item->getDiscountTaxCompensationAmount()
        );
        $netPaid = $this->moneyMath->subtract(
            $netPaid,
            (string)$item->getDiscountAmount()
        );
        if ($this->moneyMath->compare($netPaid, '0') < 0) {
            throw new InvariantViolationException(
                __('The original item net-paid amount cannot be negative.')
            );
        }

        return $this->moneyMath->normalize(bcdiv($netPaid, $quantity, 4));
    }
}
