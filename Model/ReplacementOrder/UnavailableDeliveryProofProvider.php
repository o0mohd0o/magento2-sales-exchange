<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\ReplacementOrder;

use Bonlineco\SalesExchange\Api\ReplacementDeliveryProofProviderInterface;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Safe open-source default: Magento core has no authoritative delivery event.
 */
class UnavailableDeliveryProofProvider implements
    ReplacementDeliveryProofProviderInterface
{
    public function getProof(OrderInterface $order): ?string
    {
        unset($order);

        return null;
    }
}
