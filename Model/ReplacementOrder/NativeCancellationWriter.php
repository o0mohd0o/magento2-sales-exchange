<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\ReplacementOrder;

use Bonlineco\SalesExchange\Api\BalanceCalculatorInterface;
use Bonlineco\SalesExchange\Api\Data\ExchangeInterface;
use Bonlineco\SalesExchange\Api\Data\ReplacementItemInterface;
use Bonlineco\SalesExchange\Api\History\ActorType;
use Bonlineco\SalesExchange\Api\Status\ReplacementStatus;
use Bonlineco\SalesExchange\Api\Status\StateDimension;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Creditmemo\ReturnCreditProjection;
use Bonlineco\SalesExchange\Model\Exchange;
use Bonlineco\SalesExchange\Model\HistoryFactory;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Bonlineco\SalesExchange\Model\ReplacementItemFactory;
use Bonlineco\SalesExchange\Model\ResourceModel\Exchange as ExchangeResource;
use Bonlineco\SalesExchange\Model\ResourceModel\History as HistoryResource;
use Bonlineco\SalesExchange\Model\ResourceModel\ReplacementItem as ReplacementItemResource;
use Bonlineco\SalesExchange\Model\VersionGuard;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Persist the zero-charge projection for one cancelled native replacement.
 */
class NativeCancellationWriter
{
    private ExchangeResource $exchangeResource;

    private ReplacementItemResource $replacementItemResource;

    private ReplacementItemFactory $replacementItemFactory;

    private HistoryFactory $historyFactory;

    private HistoryResource $historyResource;

    private VersionGuard $versionGuard;

    private ReturnCreditProjection $returnCreditProjection;

    private BalanceCalculatorInterface $balanceCalculator;

    private DecimalMath $moneyMath;

    public function __construct(
        ExchangeResource $exchangeResource,
        ReplacementItemResource $replacementItemResource,
        ReplacementItemFactory $replacementItemFactory,
        HistoryFactory $historyFactory,
        HistoryResource $historyResource,
        VersionGuard $versionGuard,
        ReturnCreditProjection $returnCreditProjection,
        BalanceCalculatorInterface $balanceCalculator,
        DecimalMath $moneyMath
    ) {
        $this->exchangeResource = $exchangeResource;
        $this->replacementItemResource = $replacementItemResource;
        $this->replacementItemFactory = $replacementItemFactory;
        $this->historyFactory = $historyFactory;
        $this->historyResource = $historyResource;
        $this->versionGuard = $versionGuard;
        $this->returnCreditProjection = $returnCreditProjection;
        $this->balanceCalculator = $balanceCalculator;
        $this->moneyMath = $moneyMath;
    }

    /**
     * @param array<string, mixed> $exchangeRow
     * @param array<int, array<string, mixed>> $replacementRows
     * @param array<int, array<string, mixed>> $returnRows
     * @return array{exchange: ExchangeInterface, changed: bool}
     */
    public function execute(
        ExchangeInterface $exchange,
        array $exchangeRow,
        array $replacementRows,
        array $returnRows,
        OrderInterface $order
    ): array {
        $projectedCredit = $this->returnCreditProjection->execute(
            $exchange->getNativeReturnCreditAmount(),
            $returnRows
        );
        $balance = $this->balanceCalculator->execute(
            '0.0000',
            '0.0000',
            '0.0000',
            $projectedCredit
        );
        if ($exchange->getReplacementStatus()
            === ReplacementStatus::CANCELLED
        ) {
            if ($this->moneyMath->compare(
                $exchange->getBalanceAmount(),
                $balance
            ) !== 0) {
                throw new InvariantViolationException(
                    __('The cancelled replacement balance projection is inconsistent.')
                );
            }

            return ['exchange' => $exchange, 'changed' => false];
        }

        $nextVersion = $this->versionGuard->assertCurrentAndIncrement(
            (int)$exchangeRow[ExchangeInterface::VERSION],
            (int)$exchangeRow[ExchangeInterface::VERSION],
            'exchange case'
        );
        foreach ($replacementRows as $row) {
            $currentVersion = (int)($row[
                ReplacementItemInterface::VERSION
            ] ?? 0);
            $nextItemVersion = $this->versionGuard
                ->assertCurrentAndIncrement(
                    $currentVersion,
                    $currentVersion,
                    'replacement item'
                );
            $item = $this->replacementItemFactory->create();
            $item->setData($row);
            $item->setReplacementOrderItemId(null)
                ->setVersion($nextItemVersion);
            $this->replacementItemResource->save($item);
        }

        $fromStatus = $exchange->getReplacementStatus();
        $fromNative = $exchange->getNativeReplacementAmount();
        $fromBaseNative = $exchange->getBaseNativeReplacementAmount();
        $fromBalance = $exchange->getBalanceAmount();
        $exchange->setReplacementStatus(ReplacementStatus::CANCELLED)
            ->setFeeAmount('0.0000')
            ->setNativeReplacementAmount('0.0000')
            ->setBaseNativeReplacementAmount('0.0000')
            ->setBalanceAmount($balance)
            ->setVersion($nextVersion);
        $this->exchangeResource->save($exchange);
        $this->recordHistory(
            $exchange,
            $order,
            $fromStatus,
            $fromNative,
            $fromBaseNative,
            $fromBalance,
            $balance
        );

        return ['exchange' => $exchange, 'changed' => true];
    }

    private function recordHistory(
        ExchangeInterface $exchange,
        OrderInterface $order,
        string $fromStatus,
        string $fromNative,
        string $fromBaseNative,
        string $fromBalance,
        string $balance
    ): void {
        $history = $this->historyFactory->create();
        $history->setExchangeId((int)$exchange->getEntityId())
            ->setAction('native_replacement_order_cancelled')
            ->setStatusDimension(StateDimension::REPLACEMENT)
            ->setFromValue(
                sprintf(
                    'status=%s;native=%s;base_native=%s;balance=%s',
                    $fromStatus,
                    $fromNative,
                    $fromBaseNative,
                    $fromBalance
                )
            )->setToValue(
                sprintf(
                    'status=%s;native=0.0000;base_native=0.0000;'
                    . 'balance=%s;order=%s',
                    ReplacementStatus::CANCELLED,
                    $balance,
                    (string)$order->getIncrementId()
                )
            )->setActorType(ActorType::SYSTEM)
            ->setActorId(null)
            ->setComment(
                'Synchronized from Magento native order cancellation.'
            );
        $this->historyResource->save($history);
    }
}
