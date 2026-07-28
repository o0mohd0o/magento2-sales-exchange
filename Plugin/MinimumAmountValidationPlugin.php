<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Plugin;

use Bonlineco\SalesExchange\Model\ReplacementOrder\ExecutionContext;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\ValidationRules\MinimumAmountValidationRule;

/**
 * Exempt only the exact in-process replacement quote from store minimums.
 */
class MinimumAmountValidationPlugin
{
    private ExecutionContext $executionContext;

    public function __construct(ExecutionContext $executionContext)
    {
        $this->executionContext = $executionContext;
    }

    /**
     * @param array<int, \Magento\Framework\Validation\ValidationResult> $result
     * @return array<int, \Magento\Framework\Validation\ValidationResult>
     */
    public function afterValidate(
        MinimumAmountValidationRule $subject,
        array $result,
        Quote $quote
    ): array {
        unset($subject);

        return $this->executionContext->isTrustedQuote($quote)
            ? []
            : $result;
    }
}
