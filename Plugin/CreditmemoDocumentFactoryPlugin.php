<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Plugin;

use Bonlineco\SalesExchange\Model\Creditmemo\ExecutionContext;
use Magento\Framework\DataObject;
use Magento\Sales\Api\Data\CreditmemoCommentCreationInterface;
use Magento\Sales\Api\Data\CreditmemoCreationArgumentsInterface;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order\CreditmemoDocumentFactory;

/**
 * Propagate a trusted execution marker to native save-event observers.
 */
class CreditmemoDocumentFactoryPlugin
{
    private ExecutionContext $executionContext;

    public function __construct(ExecutionContext $executionContext)
    {
        $this->executionContext = $executionContext;
    }

    /**
     * @param array<int, mixed> $items
     * @param bool|null $appendComment
     */
    public function afterCreateFromOrder(
        CreditmemoDocumentFactory $subject,
        CreditmemoInterface $creditmemo,
        OrderInterface $order,
        array $items = [],
        ?CreditmemoCommentCreationInterface $comment = null,
        $appendComment = false,
        ?CreditmemoCreationArgumentsInterface $arguments = null
    ): CreditmemoInterface {
        unset($subject, $order, $items, $comment, $appendComment);
        $extension = $arguments === null ? null : $arguments->getExtensionAttributes();
        if ($extension === null
            || !method_exists($extension, 'getBonlinecoExchangeOperationKey')
        ) {
            return $creditmemo;
        }
        $operationKey = $extension->getBonlinecoExchangeOperationKey();
        if (!$creditmemo instanceof DataObject
            || !is_string($operationKey)
            || !$this->executionContext->isActiveFor($operationKey)
        ) {
            return $creditmemo;
        }
        $creditmemo->setData(ExecutionContext::CREDITMEMO_DATA_KEY, $operationKey);

        return $creditmemo;
    }
}
