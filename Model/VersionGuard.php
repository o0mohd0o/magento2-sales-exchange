<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model;

use Bonlineco\SalesExchange\Exception\InvariantViolationException;

/**
 * Enforce integer optimistic locking for mutable exchange records.
 */
class VersionGuard
{
    public const INITIAL_VERSION = 1;

    private const MAX_VERSION = 4294967295;

    /**
     * Validate the caller's version and return the next persisted version.
     *
     * @throws InvariantViolationException
     */
    public function assertCurrentAndIncrement(
        int $incomingVersion,
        int $persistedVersion,
        string $entityLabel
    ): int {
        if ($persistedVersion < self::INITIAL_VERSION
            || $persistedVersion >= self::MAX_VERSION
        ) {
            throw new InvariantViolationException(
                __('The %1 version is invalid or exhausted.', $entityLabel)
            );
        }
        if ($incomingVersion !== $persistedVersion) {
            throw new InvariantViolationException(
                __(
                    'The %1 was changed by another process. Reload it and try again.',
                    $entityLabel
                )
            );
        }

        return $persistedVersion + 1;
    }
}
