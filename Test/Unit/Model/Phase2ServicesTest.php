<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Unit\Model;

use Bonlineco\SalesExchange\Api\AdminAction;
use Bonlineco\SalesExchange\Api\Data\CreateExchangeRequestInterface;
use Bonlineco\SalesExchange\Api\Data\ExchangeInterface;
use Bonlineco\SalesExchange\Api\Data\ReplacementSelectionInterface;
use Bonlineco\SalesExchange\Api\Data\ReturnItemInterface;
use Bonlineco\SalesExchange\Api\Data\ReturnSelectionInterface;
use Bonlineco\SalesExchange\Api\ReasonCode;
use Bonlineco\SalesExchange\Api\Status\ExchangeStatus;
use Bonlineco\SalesExchange\Api\Status\StateDimension;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Creation\CreateInputValidator;
use Bonlineco\SalesExchange\Model\Eligibility\EligibilityPolicy;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Bonlineco\SalesExchange\Model\Workflow\AdminActionMap;
use Bonlineco\SalesExchange\Model\Workflow\ReturnOutcomeResolver;
use PHPUnit\Framework\TestCase;

/**
 * Focused Phase 2 eligibility, command mapping, and input coverage.
 */
class Phase2ServicesTest extends TestCase
{
    public function testEligibilityAcceptsInWindowOrderWithReturnableQuantity(): void
    {
        (new EligibilityPolicy())->execute(
            true,
            'complete',
            ['complete'],
            1_000_000,
            1_086_399,
            1,
            true
        );

        self::assertTrue(true);
    }

    public function testEligibilityRejectsOrderOutsideWindow(): void
    {
        $this->expectException(InvariantViolationException::class);

        (new EligibilityPolicy())->execute(
            true,
            'complete',
            ['complete'],
            1_000_000,
            1_086_401,
            1,
            true
        );
    }

    public function testDraftApprovalHasServerOwnedTwoStepPlan(): void
    {
        $exchange = $this->createMock(ExchangeInterface::class);
        $exchange->method('getExchangeStatus')->willReturn(ExchangeStatus::DRAFT);
        $plan = (new AdminActionMap())->getTransitions(AdminAction::APPROVE, $exchange);

        self::assertSame(
            [
                ['dimension' => StateDimension::EXCHANGE, 'status' => ExchangeStatus::PENDING_APPROVAL],
                ['dimension' => StateDimension::EXCHANGE, 'status' => ExchangeStatus::APPROVED],
            ],
            $plan
        );
        self::assertSame(
            AdminActionMap::ACL_WAREHOUSE,
            (new AdminActionMap())->getAclResource(AdminAction::INSPECT)
        );
    }

    public function testCreateInputAcceptsCanonicalSelections(): void
    {
        $return = $this->createMock(ReturnSelectionInterface::class);
        $return->method('getOrderItemId')->willReturn(11);
        $return->method('getQuantity')->willReturn('1.0000');
        $return->method('getReasonCode')->willReturn(ReasonCode::DEFECTIVE);
        $replacement = $this->createMock(ReplacementSelectionInterface::class);
        $replacement->method('getSku')->willReturn('replacement-1');
        $replacement->method('getQuantity')->willReturn('2.0000');
        $request = $this->createMock(CreateExchangeRequestInterface::class);
        $request->method('getOrderId')->willReturn(10);
        $request->method('getActorId')->willReturn(3);
        $request->method('getReturnItems')->willReturn([$return]);
        $request->method('getReplacementItems')->willReturn([$replacement]);
        $request->method('getCustomerNote')->willReturn(null);
        $request->method('getInternalNote')->willReturn(null);

        (new CreateInputValidator(new DecimalMath(4, 12)))->execute(
            $request,
            [ReasonCode::DEFECTIVE]
        );

        self::assertTrue(true);
    }

    public function testCreateInputRejectsReasonDisabledForStore(): void
    {
        $return = $this->createMock(ReturnSelectionInterface::class);
        $return->method('getOrderItemId')->willReturn(11);
        $return->method('getQuantity')->willReturn('1.0000');
        $return->method('getReasonCode')->willReturn(ReasonCode::CHANGED_MIND);
        $replacement = $this->createMock(ReplacementSelectionInterface::class);
        $replacement->method('getSku')->willReturn('replacement-1');
        $replacement->method('getQuantity')->willReturn('1.0000');
        $request = $this->createMock(CreateExchangeRequestInterface::class);
        $request->method('getOrderId')->willReturn(10);
        $request->method('getActorId')->willReturn(3);
        $request->method('getReturnItems')->willReturn([$return]);
        $request->method('getReplacementItems')->willReturn([$replacement]);

        $this->expectException(InvariantViolationException::class);
        (new CreateInputValidator(new DecimalMath(4, 12)))->execute(
            $request,
            [ReasonCode::DEFECTIVE]
        );
    }

    public function testOutcomeIsDerivedAsPartialFromPersistedRows(): void
    {
        $status = (new ReturnOutcomeResolver(new DecimalMath(4, 12)))->execute([[
            ReturnItemInterface::RECEIPT_RESOLVED => 1,
            ReturnItemInterface::RECEIVED_QTY => '2.0000',
            ReturnItemInterface::ACCEPTED_QTY => '1.0000',
            ReturnItemInterface::REJECTED_QTY => '1.0000',
        ]]);

        self::assertSame('partially_accepted', $status);
    }
}
