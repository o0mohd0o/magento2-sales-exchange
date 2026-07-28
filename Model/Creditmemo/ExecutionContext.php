<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\Creditmemo;

use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Model\Order\Creditmemo as NativeCreditmemo;

/**
 * Request-scoped proof that a credit memo is executing through this module.
 */
class ExecutionContext
{
    public const CREDITMEMO_DATA_KEY = 'bonlineco_exchange_operation_key';

    private const OPERATION_PATTERN =
        '/^creditmemo:exchange:[1-9][0-9]*:version:[1-9][0-9]*$/D';

    private ?string $operationKey = null;

    /**
     * Execute with an unspoofable in-process marker and always clear it.
     *
     * @return mixed
     */
    public function execute(string $operationKey, callable $callback)
    {
        $this->activate($operationKey);
        try {
            return $callback();
        } finally {
            $this->operationKey = null;
        }
    }

    public function isActiveFor(?string $operationKey): bool
    {
        return $operationKey !== null
            && $this->operationKey !== null
            && hash_equals($this->operationKey, $operationKey);
    }

    public function readCreditmemoMarker(CreditmemoInterface $creditmemo): ?string
    {
        if (!method_exists($creditmemo, 'getData')) {
            return null;
        }
        $value = $creditmemo->getData(self::CREDITMEMO_DATA_KEY);
        if (!is_string($value) || !preg_match(self::OPERATION_PATTERN, $value)) {
            return null;
        }

        return $value;
    }

    public function assertTrustedRefund(
        CreditmemoInterface $creditmemo,
        int $expectedEntityId,
        string $operationKey
    ): void {
        $marker = $this->readCreditmemoMarker($creditmemo);
        if ($expectedEntityId <= 0
            || (int)$creditmemo->getEntityId() !== $expectedEntityId
            || trim((string)$creditmemo->getIncrementId()) === ''
            || (int)$creditmemo->getState() !== NativeCreditmemo::STATE_REFUNDED
            || !$this->isActiveFor($operationKey)
            || $marker === null
            || !hash_equals($operationKey, $marker)
        ) {
            throw new InvariantViolationException(
                __(
                    'Magento did not return the trusted refunded credit memo '
                    . 'created for this exchange operation.'
                )
            );
        }
    }

    private function activate(string $operationKey): void
    {
        if (!preg_match(self::OPERATION_PATTERN, $operationKey)) {
            throw new InvariantViolationException(
                __('The exchange credit memo operation key is invalid.')
            );
        }
        if ($this->operationKey !== null) {
            throw new InvariantViolationException(
                __('An exchange credit memo operation is already active in this request.')
            );
        }
        $this->operationKey = $operationKey;
    }
}
