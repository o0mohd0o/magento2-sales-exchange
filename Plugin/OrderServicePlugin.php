<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Plugin;

use Bonlineco\SalesExchange\Model\ReplacementOrder\NativeOrderCancellationSynchronizer;
use Magento\Sales\Model\Service\OrderService;

/**
 * Delegate native cancellation to the atomic exchange synchronizer.
 */
class OrderServicePlugin
{
    private NativeOrderCancellationSynchronizer $synchronizer;

    public function __construct(
        NativeOrderCancellationSynchronizer $synchronizer
    ) {
        $this->synchronizer = $synchronizer;
    }

    /**
     * @param callable $proceed
     * @param int $id
     */
    public function aroundCancel(
        OrderService $subject,
        callable $proceed,
        $id
    ): bool {
        unset($subject);

        return $this->synchronizer->execute((int)$id, $proceed);
    }
}
