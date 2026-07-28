<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\Workflow;

use Bonlineco\SalesExchange\Api\Data\ExchangeInterface;
use Bonlineco\SalesExchange\Api\History\ActorType;
use Bonlineco\SalesExchange\Api\Status\ReturnStatus;
use Bonlineco\SalesExchange\Api\Status\StateDimension;
use Bonlineco\SalesExchange\Api\TransitionExchangeInterface;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\ResourceModel\ReturnItem as ReturnItemResource;

/**
 * Finalize or recover inspection exclusively from persisted return rows.
 */
class InspectionFinalizer
{
    private TransitionExchangeInterface $transitionExchange;

    private ReturnItemResource $returnItemResource;

    private ReturnOutcomeResolver $returnOutcomeResolver;

    public function __construct(
        TransitionExchangeInterface $transitionExchange,
        ReturnItemResource $returnItemResource,
        ReturnOutcomeResolver $returnOutcomeResolver
    ) {
        $this->transitionExchange = $transitionExchange;
        $this->returnItemResource = $returnItemResource;
        $this->returnOutcomeResolver = $returnOutcomeResolver;
    }

    public function execute(
        ExchangeInterface $exchange,
        int $actorId,
        ?string $comment
    ): ExchangeInterface {
        $exchangeId = (int)$exchange->getEntityId();
        if ($exchangeId <= 0 || $actorId <= 0) {
            throw new InvariantViolationException(
                __('A valid exchange and admin actor are required to finalize inspection.')
            );
        }

        $terminalStatuses = [
            ReturnStatus::ACCEPTED,
            ReturnStatus::PARTIALLY_ACCEPTED,
            ReturnStatus::REJECTED,
        ];
        if (in_array($exchange->getReturnStatus(), $terminalStatuses, true)) {
            $derived = $this->deriveOutcome($exchangeId);
            if ($derived !== $exchange->getReturnStatus()) {
                throw new InvariantViolationException(
                    __('The persisted inspection outcome does not match the closed return status.')
                );
            }

            return $exchange;
        }

        if ($exchange->getReturnStatus() === ReturnStatus::RECEIVED) {
            $exchange = $this->transitionExchange->execute(
                $exchangeId,
                $exchange->getVersion(),
                StateDimension::RETURN,
                ReturnStatus::INSPECTED,
                ActorType::ADMIN,
                $actorId,
                $comment
            );
        }
        if ($exchange->getReturnStatus() !== ReturnStatus::INSPECTED) {
            throw new InvariantViolationException(
                __('Inspection can only be finalized after receipt and line inspection.')
            );
        }

        return $this->transitionExchange->execute(
            $exchangeId,
            $exchange->getVersion(),
            StateDimension::RETURN,
            $this->deriveOutcome($exchangeId),
            ActorType::ADMIN,
            $actorId,
            $comment
        );
    }

    private function deriveOutcome(int $exchangeId): string
    {
        return $this->returnOutcomeResolver->execute(
            $this->returnItemResource->getRowsByExchangeId($exchangeId)
        );
    }
}
