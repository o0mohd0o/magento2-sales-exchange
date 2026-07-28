<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Plugin;

use Bonlineco\SalesExchange\Model\ReplacementOrder\NativeShipmentSynchronizer;
use Magento\Sales\Api\Data\ShipmentCommentCreationInterface;
use Magento\Sales\Api\Data\ShipmentCreationArgumentsInterface;
use Magento\Sales\Model\ShipOrder;

/**
 * Delegate native shipment creation to the atomic exchange synchronizer.
 */
class ShipOrderPlugin
{
    private NativeShipmentSynchronizer $synchronizer;

    public function __construct(NativeShipmentSynchronizer $synchronizer)
    {
        $this->synchronizer = $synchronizer;
    }

    /**
     * @param callable $proceed
     * @param int $orderId
     * @param array<int, mixed> $items
     * @param bool $notify
     * @param bool $appendComment
     * @param array<int, mixed> $tracks
     * @param array<int, mixed> $packages
     */
    public function aroundExecute(
        ShipOrder $subject,
        callable $proceed,
        $orderId,
        array $items = [],
        $notify = false,
        $appendComment = false,
        ?ShipmentCommentCreationInterface $comment = null,
        array $tracks = [],
        array $packages = [],
        ?ShipmentCreationArgumentsInterface $arguments = null
    ): int {
        unset($subject);

        return $this->synchronizer->execute(
            (int)$orderId,
            $items,
            (bool)$notify,
            static function () use (
                $proceed,
                $orderId,
                $items,
                $notify,
                $appendComment,
                $comment,
                $tracks,
                $packages,
                $arguments
            ): int {
                return (int)$proceed(
                    $orderId,
                    $items,
                    $notify,
                    $appendComment,
                    $comment,
                    $tracks,
                    $packages,
                    $arguments
                );
            }
        );
    }
}
