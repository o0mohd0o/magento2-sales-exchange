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
use Bonlineco\SalesExchange\Api\Status\ExchangeStatus;
use Bonlineco\SalesExchange\Api\Status\ReplacementStatus;
use Bonlineco\SalesExchange\Api\Status\ReturnStatus;
use Bonlineco\SalesExchange\Api\Status\SettlementStatus;
use Bonlineco\SalesExchange\Api\Status\StateDimension;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;

/**
 * Map server-owned route actions to fixed workflow operations and ACLs.
 */
class AdminActionMap
{
    public const ACL_VIEW = 'Bonlineco_SalesExchange::view';
    public const ACL_CREATE = 'Bonlineco_SalesExchange::create';
    public const ACL_SALES_ORDER_VIEW = 'Magento_Sales::actions_view';
    public const ACL_APPROVE = 'Bonlineco_SalesExchange::approve';
    public const ACL_WAREHOUSE = 'Bonlineco_SalesExchange::warehouse';
    public const ACL_CANCEL = 'Bonlineco_SalesExchange::cancel';

    /**
     * @return array<int, array{dimension: string, status: string}>
     */
    public function getTransitions(string $action, ExchangeInterface $exchange): array
    {
        if ($action === AdminAction::APPROVE) {
            if ($exchange->getExchangeStatus() === ExchangeStatus::DRAFT) {
                return [
                    [
                        'dimension' => StateDimension::EXCHANGE,
                        'status' => ExchangeStatus::PENDING_APPROVAL,
                    ],
                    [
                        'dimension' => StateDimension::EXCHANGE,
                        'status' => ExchangeStatus::APPROVED,
                    ],
                ];
            }

            return [[
                'dimension' => StateDimension::EXCHANGE,
                'status' => ExchangeStatus::APPROVED,
            ]];
        }
        if ($action === AdminAction::AUTHORIZE_RETURN) {
            return [[
                'dimension' => StateDimension::RETURN,
                'status' => ReturnStatus::AUTHORIZED,
            ]];
        }
        if ($action === AdminAction::START) {
            return [[
                'dimension' => StateDimension::EXCHANGE,
                'status' => ExchangeStatus::IN_PROGRESS,
            ]];
        }
        if ($action === AdminAction::REJECT) {
            $transitions = [];
            if ($exchange->getReplacementStatus() !== ReplacementStatus::CANCELLED) {
                $transitions[] = [
                    'dimension' => StateDimension::REPLACEMENT,
                    'status' => ReplacementStatus::CANCELLED,
                ];
            }
            if ($exchange->getSettlementStatus() !== SettlementStatus::CANCELLED) {
                $transitions[] = [
                    'dimension' => StateDimension::SETTLEMENT,
                    'status' => SettlementStatus::CANCELLED,
                ];
            }
            $transitions[] = [
                'dimension' => StateDimension::EXCHANGE,
                'status' => ExchangeStatus::REJECTED,
            ];

            return $transitions;
        }
        if ($action === AdminAction::CANCEL) {
            return [[
                'dimension' => StateDimension::EXCHANGE,
                'status' => ExchangeStatus::CANCELLED,
            ]];
        }
        if (in_array(
            $action,
            [AdminAction::RECEIVE, AdminAction::INSPECT, AdminAction::FINALIZE_INSPECTION],
            true
        )) {
            return [];
        }

        throw new InvariantViolationException(__('Unknown admin exchange action "%1".', $action));
    }

    public function getAclResource(string $action): string
    {
        if ($action === AdminAction::APPROVE) {
            return self::ACL_APPROVE;
        }
        if (in_array(
            $action,
            [
                AdminAction::AUTHORIZE_RETURN,
                AdminAction::START,
                AdminAction::RECEIVE,
                AdminAction::INSPECT,
                AdminAction::FINALIZE_INSPECTION,
            ],
            true
        )) {
            return self::ACL_WAREHOUSE;
        }
        if (in_array($action, [AdminAction::REJECT, AdminAction::CANCEL], true)) {
            return self::ACL_CANCEL;
        }

        throw new InvariantViolationException(__('Unknown admin exchange action "%1".', $action));
    }
}
