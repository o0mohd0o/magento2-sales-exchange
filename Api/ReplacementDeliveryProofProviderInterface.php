<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Api;

use Magento\Sales\Api\Data\OrderInterface;

/**
 * Resolve a trusted, adapter-owned proof that a replacement was delivered.
 *
 * The reusable module deliberately has no courier-status assumptions. A
 * deployment adapter may replace this service and return a stable reference
 * only after its authoritative carrier or order-tracking workflow confirms
 * delivery.
 *
 * @api
 */
interface ReplacementDeliveryProofProviderInterface
{
    public function getProof(OrderInterface $order): ?string;
}
