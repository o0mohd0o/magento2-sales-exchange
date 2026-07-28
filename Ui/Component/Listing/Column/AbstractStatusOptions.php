<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Ui\Component\Listing\Column;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\Phrase;

/**
 * Build select options for a workflow status dimension.
 */
abstract class AbstractStatusOptions implements OptionSourceInterface
{
    /**
     * Return status value-to-label mappings.
     *
     * @return array<string, Phrase>
     */
    abstract protected function getLabels(): array;

    /**
     * @return array<int, array{value: string, label: Phrase}>
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->getLabels() as $value => $label) {
            $options[] = [
                'value' => $value,
                'label' => $label,
            ];
        }

        return $options;
    }
}
