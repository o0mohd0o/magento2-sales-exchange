<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Api\Status;

/**
 * Overall exchange case statuses.
 *
 * @api
 */
abstract class ExchangeStatus
{
    public const DRAFT = 'draft';
    public const PENDING_APPROVAL = 'pending_approval';
    public const APPROVED = 'approved';
    public const IN_PROGRESS = 'in_progress';
    public const COMPLETED = 'completed';
    public const REJECTED = 'rejected';
    public const CANCELLED = 'cancelled';

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::DRAFT,
            self::PENDING_APPROVAL,
            self::APPROVED,
            self::IN_PROGRESS,
            self::COMPLETED,
            self::REJECTED,
            self::CANCELLED,
        ];
    }

    /**
     * @return string[]
     */
    public static function terminal(): array
    {
        return [
            self::COMPLETED,
            self::REJECTED,
            self::CANCELLED,
        ];
    }

}
