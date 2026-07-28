<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\Creditmemo;

use Magento\Sales\Api\Data\CreditmemoCreationArgumentsExtensionFactory;
use Magento\Sales\Api\Data\CreditmemoCreationArgumentsInterface;
use Magento\Sales\Api\Data\CreditmemoCreationArgumentsInterfaceFactory;
use Magento\Sales\Api\Data\CreditmemoItemCreationInterface;
use Magento\Sales\Api\Data\CreditmemoItemCreationInterfaceFactory;

/**
 * Build the exact native DTOs shared by preview and offline execution.
 */
class RequestBuilder
{
    private CreditmemoItemCreationInterfaceFactory $itemFactory;

    private CreditmemoCreationArgumentsInterfaceFactory $argumentsFactory;

    private CreditmemoCreationArgumentsExtensionFactory $extensionFactory;

    public function __construct(
        CreditmemoItemCreationInterfaceFactory $itemFactory,
        CreditmemoCreationArgumentsInterfaceFactory $argumentsFactory,
        CreditmemoCreationArgumentsExtensionFactory $extensionFactory
    ) {
        $this->itemFactory = $itemFactory;
        $this->argumentsFactory = $argumentsFactory;
        $this->extensionFactory = $extensionFactory;
    }

    /**
     * @return CreditmemoItemCreationInterface[]
     */
    public function buildItems(Plan $plan): array
    {
        $items = [];
        foreach ($plan->getQuantitiesByOrderItem() as $orderItemId => $quantity) {
            /** @var CreditmemoItemCreationInterface $item */
            $item = $this->itemFactory->create();
            // Magento's @api DTO is float-documented but untyped. Passing the
            // canonical decimal string avoids doing domain arithmetic in floats.
            $item->setOrderItemId($orderItemId)->setQty($quantity);
            $items[] = $item;
        }

        return $items;
    }

    public function buildArguments(
        Plan $plan,
        string $operationKey
    ): CreditmemoCreationArgumentsInterface
    {
        /** @var CreditmemoCreationArgumentsInterface $arguments */
        $arguments = $this->argumentsFactory->create();
        $arguments->setShippingAmount('0.0000')
            ->setAdjustmentPositive('0.0000')
            ->setAdjustmentNegative('0.0000');
        $extension = $this->extensionFactory->create();
        $extension->setReturnToStockItems($plan->getReturnToStockOrderItemIds())
            ->setBonlinecoExchangeOperationKey($operationKey);
        $arguments->setExtensionAttributes($extension);

        return $arguments;
    }
}
