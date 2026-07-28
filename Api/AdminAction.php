<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Api;

/**
 * Server-owned admin workflow action identifiers.
 *
 * @api
 */
abstract class AdminAction
{
    public const APPROVE = 'approve';
    public const AUTHORIZE_RETURN = 'authorize_return';
    public const START = 'start';
    public const RECEIVE = 'receive';
    public const INSPECT = 'inspect';
    public const FINALIZE_INSPECTION = 'finalize_inspection';
    public const REJECT = 'reject';
    public const CANCEL = 'cancel';
}
