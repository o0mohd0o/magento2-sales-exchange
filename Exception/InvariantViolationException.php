<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * Raised when an exchange domain invariant would be violated.
 */
class InvariantViolationException extends LocalizedException
{
}
