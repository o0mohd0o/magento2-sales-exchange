<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\Workflow;

use Bonlineco\SalesExchange\Api\AdminAction;
use Bonlineco\SalesExchange\Api\Data\ExchangeInterface;
use Bonlineco\SalesExchange\Api\ExchangeRepositoryInterface;
use Bonlineco\SalesExchange\Api\History\ActorType;
use Bonlineco\SalesExchange\Api\Status\ReturnStatus;
use Bonlineco\SalesExchange\Api\Status\StateDimension;
use Bonlineco\SalesExchange\Api\TransitionExchangeInterface;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;

/**
 * Execute only the explicit admin workflow operations exposed by POST routes.
 */
class AdminWorkflow
{
    private AdminActionMap $actionMap;

    private ExchangeRepositoryInterface $exchangeRepository;

    private TransitionExchangeInterface $transitionExchange;

    private WarehouseRecorder $warehouseRecorder;

    private InspectionFinalizer $inspectionFinalizer;

    public function __construct(
        AdminActionMap $actionMap,
        ExchangeRepositoryInterface $exchangeRepository,
        TransitionExchangeInterface $transitionExchange,
        WarehouseRecorder $warehouseRecorder,
        InspectionFinalizer $inspectionFinalizer
    ) {
        $this->actionMap = $actionMap;
        $this->exchangeRepository = $exchangeRepository;
        $this->transitionExchange = $transitionExchange;
        $this->warehouseRecorder = $warehouseRecorder;
        $this->inspectionFinalizer = $inspectionFinalizer;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function execute(
        string $action,
        int $exchangeId,
        int $expectedVersion,
        int $actorId,
        array $payload = []
    ): ExchangeInterface {
        if ($exchangeId <= 0 || $expectedVersion <= 0 || $actorId <= 0) {
            throw new InvariantViolationException(
                __('A valid exchange, version, and admin actor are required.')
            );
        }
        $comment = $this->normalizeComment($payload['comment'] ?? null);
        $exchange = $this->exchangeRepository->getById($exchangeId);
        if ($exchange->getVersion() !== $expectedVersion) {
            throw new InvariantViolationException(
                __('The exchange case was changed by another process. Reload it and try again.')
            );
        }

        if ($action === AdminAction::RECEIVE) {
            return $this->receive($exchange, $actorId, $payload, $comment);
        }
        if ($action === AdminAction::INSPECT) {
            return $this->inspect($exchange, $actorId, $payload, $comment);
        }
        if ($action === AdminAction::FINALIZE_INSPECTION) {
            return $this->inspectionFinalizer->execute($exchange, $actorId, $comment);
        }

        foreach ($this->actionMap->getTransitions($action, $exchange) as $transition) {
            $this->transitionExchange->execute(
                $exchangeId,
                $exchange->getVersion(),
                $transition['dimension'],
                $transition['status'],
                ActorType::ADMIN,
                $actorId,
                $comment
            );
            $exchange = $this->exchangeRepository->getById($exchangeId);
        }

        return $exchange;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function receive(
        ExchangeInterface $exchange,
        int $actorId,
        array $payload,
        ?string $comment
    ): ExchangeInterface {
        $this->warehouseRecorder->recordReceipt(
            (int)$exchange->getEntityId(),
            $exchange->getVersion(),
            (int)($payload['item_id'] ?? 0),
            (int)($payload['item_version'] ?? 0),
            (string)($payload['received_qty'] ?? ''),
            $actorId,
            $comment
        );
        $exchange = $this->exchangeRepository->getById((int)$exchange->getEntityId());
        if ($this->isFinalizeRequested($payload)) {
            $this->transitionExchange->execute(
                (int)$exchange->getEntityId(),
                $exchange->getVersion(),
                StateDimension::RETURN,
                ReturnStatus::RECEIVED,
                ActorType::ADMIN,
                $actorId,
                $comment
            );
            $exchange = $this->exchangeRepository->getById((int)$exchange->getEntityId());
        }

        return $exchange;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function inspect(
        ExchangeInterface $exchange,
        int $actorId,
        array $payload,
        ?string $comment
    ): ExchangeInterface {
        $this->warehouseRecorder->recordInspection(
            (int)$exchange->getEntityId(),
            $exchange->getVersion(),
            (int)($payload['item_id'] ?? 0),
            (int)($payload['item_version'] ?? 0),
            (string)($payload['accepted_qty'] ?? ''),
            (string)($payload['rejected_qty'] ?? ''),
            (string)($payload['condition_code'] ?? ''),
            (string)($payload['disposition'] ?? ''),
            $actorId,
            $comment
        );
        $exchange = $this->exchangeRepository->getById((int)$exchange->getEntityId());
        if (!$this->isFinalizeRequested($payload)) {
            return $exchange;
        }

        return $this->inspectionFinalizer->execute($exchange, $actorId, $comment);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function isFinalizeRequested(array $payload): bool
    {
        return (string)($payload['finalize'] ?? '0') === '1';
    }

    /**
     * @param mixed $comment
     */
    private function normalizeComment($comment): ?string
    {
        if (!is_string($comment)) {
            return null;
        }
        $comment = trim($comment);
        if (mb_strlen($comment) > 1000) {
            throw new InvariantViolationException(
                __('An action comment cannot exceed 1000 characters.')
            );
        }

        return $comment === '' ? null : $comment;
    }
}
