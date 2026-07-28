<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Plugin;

use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\ReplacementOrder\ExecutionContext;
use Bonlineco\SalesExchange\Model\ReplacementOrder\Marker;
use Magento\Framework\Model\AbstractModel;
use Magento\Quote\Model\Quote\Item;
use Magento\Quote\Model\Quote\Item\ToOrderItem;
use Magento\Sales\Api\Data\OrderItemInterface;

/**
 * Verify and preserve the trusted replacement-line marker during conversion.
 */
class OrderItemMarkerConverterPlugin
{
    private ExecutionContext $executionContext;

    public function __construct(ExecutionContext $executionContext)
    {
        $this->executionContext = $executionContext;
    }

    /**
     * @param Item|\Magento\Quote\Model\Quote\Address\Item $item
     * @param array<string, mixed> $data
     */
    public function afterConvert(
        ToOrderItem $subject,
        OrderItemInterface $result,
        $item,
        array $data = []
    ): OrderItemInterface {
        unset($subject, $data);
        $sourceMarker = is_object($item)
            && is_callable([$item, 'getData'])
                ? $item->getData(Marker::REPLACEMENT_ITEM_ID)
                : null;
        $resultMarker = $result instanceof AbstractModel
            ? $result->getData(Marker::REPLACEMENT_ITEM_ID)
            : null;
        if (!$item instanceof Item
            || !$this->executionContext->isTrustedQuote($item->getQuote())
        ) {
            if ($sourceMarker !== null || $resultMarker !== null) {
                throw new InvariantViolationException(
                    __('Replacement item markers were converted outside their trusted execution context.')
                );
            }

            return $result;
        }
        $replacementItemId = $sourceMarker;
        if (!is_int($replacementItemId) && !is_string($replacementItemId)) {
            throw new InvariantViolationException(
                __('A trusted replacement quote item is missing its marker.')
            );
        }
        $replacementItemId = (int)$replacementItemId;
        if ($replacementItemId <= 0 || !$result instanceof AbstractModel) {
            throw new InvariantViolationException(
                __('A trusted replacement order item marker is invalid.')
            );
        }
        $converted = $result->getData(Marker::REPLACEMENT_ITEM_ID);
        if ($converted !== null && (int)$converted !== $replacementItemId) {
            throw new InvariantViolationException(
                __('Magento converted a different replacement item marker.')
            );
        }
        $result->setData(Marker::REPLACEMENT_ITEM_ID, $replacementItemId);

        return $result;
    }
}
