<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\Creation;

/**
 * Canonical catalog snapshot used while creating a draft line.
 */
class ReplacementSnapshot
{
    private int $productId;

    private string $sku;

    private string $name;

    private string $unitPrice;

    public function __construct(int $productId, string $sku, string $name, string $unitPrice)
    {
        $this->productId = $productId;
        $this->sku = $sku;
        $this->name = $name;
        $this->unitPrice = $unitPrice;
    }

    public function getProductId(): int
    {
        return $this->productId;
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getUnitPrice(): string
    {
        return $this->unitPrice;
    }
}
