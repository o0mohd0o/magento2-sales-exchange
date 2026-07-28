<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model;

use Bonlineco\SalesExchange\Api\Status\ReplacementStatus;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;

/**
 * Select the financially effective replacement charge by workflow status.
 *
 * The native total is authoritative from ORDERED onward, including when it is
 * exactly zero. A cancelled replacement retains its audit snapshots but has no
 * replacement financial effect. Exchange fees remain outside this projection.
 */
class NativeReplacementProjection
{
    private DecimalMath $moneyMath;

    public function __construct(DecimalMath $moneyMath)
    {
        $this->moneyMath = $moneyMath;
    }

    public function execute(
        string $replacementStatus,
        string $approvedAmount,
        string $shippingAmount,
        string $nativeAmount
    ): string {
        if (!in_array($replacementStatus, ReplacementStatus::all(), true)) {
            throw new InvariantViolationException(
                __('Unknown replacement status "%1".', $replacementStatus)
            );
        }

        $approved = $this->moneyMath->assertNonNegative(
            $approvedAmount,
            'Approved replacement amount'
        );
        $shipping = $this->moneyMath->assertNonNegative(
            $shippingAmount,
            'Approved replacement shipping amount'
        );
        $native = $this->moneyMath->assertNonNegative(
            $nativeAmount,
            'Native replacement amount'
        );

        if ($replacementStatus === ReplacementStatus::READY) {
            return $this->moneyMath->add($approved, $shipping);
        }
        if (in_array(
            $replacementStatus,
            [
                ReplacementStatus::ORDERED,
                ReplacementStatus::SHIPPED,
                ReplacementStatus::DELIVERED,
            ],
            true
        )) {
            return $native;
        }

        return '0.0000';
    }
}
