<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\Config\Source;

use Bonlineco\SalesExchange\Api\ReasonCode;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Stable reason-code labels for configuration and admin forms.
 */
class Reason implements OptionSourceInterface
{
    /**
     * @return array<int, array<string, string|\Magento\Framework\Phrase>>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => ReasonCode::WRONG_ITEM, 'label' => __('Wrong item received')],
            ['value' => ReasonCode::DAMAGED, 'label' => __('Damaged on arrival')],
            ['value' => ReasonCode::DEFECTIVE, 'label' => __('Defective product')],
            ['value' => ReasonCode::SIZE_OR_FIT, 'label' => __('Size or fit issue')],
            ['value' => ReasonCode::CHANGED_MIND, 'label' => __('Customer changed mind')],
            ['value' => ReasonCode::OTHER, 'label' => __('Other')],
        ];
    }

    public function getLabel(string $code): \Magento\Framework\Phrase
    {
        foreach ($this->toOptionArray() as $option) {
            if ($option['value'] === $code) {
                return $option['label'];
            }
        }

        return __('Unknown');
    }
}
