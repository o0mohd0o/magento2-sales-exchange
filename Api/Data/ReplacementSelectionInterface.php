<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Api\Data;

/**
 * Catalog SKU selected as a draft replacement line.
 *
 * @api
 */
interface ReplacementSelectionInterface
{
    public const SKU = 'sku';
    public const QUANTITY = 'quantity';

    public function getSku(): string;

    public function setSku(string $sku): self;

    public function getQuantity(): string;

    public function setQuantity(string $quantity): self;
}
