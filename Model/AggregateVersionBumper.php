<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model;

use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\ResourceModel\Exchange as ExchangeResource;

/**
 * Advance the parent case version after an actual child mutation.
 */
class AggregateVersionBumper
{
    private ExchangeResource $exchangeResource;

    private VersionGuard $versionGuard;

    public function __construct(
        ExchangeResource $exchangeResource,
        VersionGuard $versionGuard
    ) {
        $this->exchangeResource = $exchangeResource;
        $this->versionGuard = $versionGuard;
    }

    /**
     * The caller must already hold the exchange row lock in its transaction.
     */
    public function execute(int $exchangeId, int $lockedVersion): int
    {
        $nextVersion = $this->versionGuard->assertCurrentAndIncrement(
            $lockedVersion,
            $lockedVersion,
            'exchange case'
        );
        if (!$this->exchangeResource->updateVersion(
            $exchangeId,
            $lockedVersion,
            $nextVersion
        )) {
            throw new InvariantViolationException(
                __('The exchange case changed while its child record was being saved.')
            );
        }

        return $nextVersion;
    }
}
