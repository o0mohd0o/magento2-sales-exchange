<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Unit\Fixture;

use Magento\Framework\Api\ExtensionAttributesInterface;

/**
 * Test seam for the module's generated credit-memo extension attribute.
 */
interface CreditmemoCreationArgumentsExtensionStubInterface extends
    ExtensionAttributesInterface
{
    /**
     * @return string|null
     */
    public function getBonlinecoExchangeOperationKey(): ?string;
}
