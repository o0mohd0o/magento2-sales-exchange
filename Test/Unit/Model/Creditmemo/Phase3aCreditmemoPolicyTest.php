<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Unit\Model\Creditmemo;

use Bonlineco\SalesExchange\Api\Data\ReturnItemInterface;
use Bonlineco\SalesExchange\Api\DocumentLinkRepositoryInterface;
use Bonlineco\SalesExchange\Api\ExchangeRepositoryInterface;
use Bonlineco\SalesExchange\Api\ReplacementItemRepositoryInterface;
use Bonlineco\SalesExchange\Api\ReturnItemRepositoryInterface;
use Bonlineco\SalesExchange\Api\DispositionCode;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Creditmemo\DocumentValidator;
use Bonlineco\SalesExchange\Model\Creditmemo\Plan;
use Bonlineco\SalesExchange\Model\Creditmemo\Planner;
use Bonlineco\SalesExchange\Model\Creditmemo\ReturnCreditProjection;
use Bonlineco\SalesExchange\Model\FinancialRowCalculator;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Bonlineco\SalesExchange\Model\ReturnableOrderItemValidator;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Api\Data\CreditmemoItemInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Model\Order\Creditmemo\Item as CreditmemoItem;
use PHPUnit\Framework\TestCase;

/**
 * Policy coverage for exact, offline Phase 3A credit-memo execution.
 */
class Phase3aCreditmemoPolicyTest extends TestCase
{
    private DecimalMath $moneyMath;

    private DecimalMath $quantityMath;

    protected function setUp(): void
    {
        $this->moneyMath = new DecimalMath();
        $this->quantityMath = new DecimalMath(4, 12);
    }

    public function testPublicDocumentLinkRepositoryIsReadOnly(): void
    {
        self::assertFalse(method_exists(DocumentLinkRepositoryInterface::class, 'save'));
        self::assertFalse(method_exists(ExchangeRepositoryInterface::class, 'save'));
        self::assertFalse(method_exists(ReturnItemRepositoryInterface::class, 'save'));
        self::assertFalse(method_exists(ReplacementItemRepositoryInterface::class, 'save'));
    }

    public function testPlannerUsesOnlyAcceptedUncreditedInvoicedParentQuantity(): void
    {
        $orderItem = $this->orderItem(11, 5, 'simple', null, '2.0000', '0.5000');

        $plan = $this->planner()->execute($this->order([$orderItem]), [[
            ReturnItemInterface::ENTITY_ID => 21,
            ReturnItemInterface::ORDER_ITEM_ID => 11,
            ReturnItemInterface::ACCEPTED_QTY => '1.5000',
            ReturnItemInterface::CREDITED_QTY => '0.5000',
            ReturnItemInterface::DISPOSITION => DispositionCode::RESTOCK,
        ]]);

        self::assertSame([11 => '1.0000'], $plan->getQuantitiesByOrderItem());
        self::assertSame([11], $plan->getReturnToStockOrderItemIds());
        self::assertSame(
            ['quantity' => '1.0000', 'credited_qty' => '0.5000'],
            $plan->getReturnItemUpdates()[21]
        );
    }

    public function testPlannerRefusesAcceptedQuantityThatIsNotInvoiced(): void
    {
        $order = $this->order([
            $this->orderItem(11, 5, 'simple', null, '1.0000', '0.0000'),
        ]);
        $this->expectException(InvariantViolationException::class);

        $this->planner()->execute($order, [[
            ReturnItemInterface::ENTITY_ID => 21,
            ReturnItemInterface::ORDER_ITEM_ID => 11,
            ReturnItemInterface::ACCEPTED_QTY => '2.0000',
            ReturnItemInterface::CREDITED_QTY => '0.0000',
            ReturnItemInterface::DISPOSITION => DispositionCode::RESTOCK,
        ]]);
    }

    public function testPlannerNeverReturnsQuarantinedLineToStock(): void
    {
        $order = $this->order([
            $this->orderItem(11, 5, 'configurable', null, '1.0000', '0.0000'),
        ]);

        $plan = $this->planner()->execute($order, [[
            ReturnItemInterface::ENTITY_ID => 21,
            ReturnItemInterface::ORDER_ITEM_ID => 11,
            ReturnItemInterface::ACCEPTED_QTY => '1.0000',
            ReturnItemInterface::CREDITED_QTY => '0.0000',
            ReturnItemInterface::DISPOSITION => DispositionCode::QUARANTINE,
        ]]);

        self::assertSame([], $plan->getReturnToStockOrderItemIds());
    }

    public function testProjectionUsesNativeActualPlusOnlyUncreditedEstimate(): void
    {
        $projection = $this->projection();
        $rows = [[
            ReturnItemInterface::ACCEPTED_QTY => '3.0000',
            ReturnItemInterface::CREDITED_QTY => '1.0000',
            ReturnItemInterface::UNIT_CREDIT_AMOUNT => '33.3333',
        ]];

        self::assertSame('76.6666', $projection->execute('10.0000', $rows));
        $this->expectException(InvariantViolationException::class);
        $projection->assertFullyCredited($rows);
    }

    public function testValidatorUsesLockedCorePreviewDespiteApprovedEstimateVariance(): void
    {
        [$creditmemo, $order] = $this->simpleDocument('100.0133', '100.0133');

        $this->validator()->assertPreview(
            $creditmemo,
            $order,
            'EGP',
            'EGP',
            '100.0000',
            new Plan([11 => '1.0000'], [], [])
        );

        self::assertTrue(true);
    }

    public function testValidatorPermitsCoreApprovedAndActualZeroTotals(): void
    {
        [$creditmemo, $order] = $this->simpleDocument('0.0000', '0.0000');

        $this->validator()->assertPreview(
            $creditmemo,
            $order,
            'EGP',
            'EGP',
            '0.0000',
            new Plan([11 => '1.0000'], [], [])
        );

        self::assertTrue(true);
    }

    public function testValidatorRejectsUnrepresentedCustomTotal(): void
    {
        [$creditmemo, $order] = $this->simpleDocument('100.0000', '99.0000');
        $this->expectException(InvariantViolationException::class);

        $this->validator()->assertPreview(
            $creditmemo,
            $order,
            'EGP',
            'EGP',
            '100.0000',
            new Plan([11 => '1.0000'], [], [])
        );
    }

    public function testValidatorRequiresExactConfigurableParentAndGeneratedChildQty(): void
    {
        $parent = $this->orderItem(
            11,
            5,
            'configurable',
            null,
            '1.0000',
            '0.0000',
            '100.0000'
        );
        $child = $this->orderItem(12, 5, 'simple', 11, '1.0000', '0.0000');
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(5);
        $order->method('getItems')->willReturn([$parent, $child]);
        $creditmemo = $this->creditmemo(
            '100.0000',
            '100.0000',
            [
                $this->creditmemoItem(11, '1.0000', '100.0000'),
                $this->creditmemoItem(12, '0.5000'),
            ]
        );
        $this->expectException(InvariantViolationException::class);

        $this->validator()->assertPreview(
            $creditmemo,
            $order,
            'EGP',
            'EGP',
            '100.0000',
            new Plan([11 => '1.0000'], [], [])
        );
    }

    public function testValidatorAllowsExactLastDocumentTaxDeltaFromOrderRemainder(): void
    {
        $orderItem = $this->orderItem(
            11,
            5,
            'simple',
            null,
            '1.0000',
            '0.0000',
            '100.0000',
            '1.0000'
        );
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(5);
        $order->method('getItems')->willReturn([$orderItem]);
        $order->method('getTaxInvoiced')->willReturn('1.0133');
        $order->method('getTaxRefunded')->willReturn('0.0000');
        $creditmemo = $this->creditmemo(
            '101.0133',
            '100.0000',
            [$this->creditmemoItem(11, '1.0000', '100.0000', '1.0000')]
        );
        $creditmemo->method('getTaxAmount')->willReturn('1.0133');
        $creditmemo->method('getBaseTaxAmount')->willReturn('1.0133');
        $order->method('getBaseTaxInvoiced')->willReturn('1.0133');
        $order->method('getBaseTaxRefunded')->willReturn('0.0000');

        $this->validator()->assertPreview(
            $creditmemo,
            $order,
            'EGP',
            'EGP',
            '101.0000',
            new Plan([11 => '1.0000'], [], [])
        );

        self::assertTrue(true);
    }

    public function testPersistedReplayDoesNotUsePostRefundSourceRemainder(): void
    {
        [$creditmemo, $order] = $this->simpleDocument('100.0000', '100.0000');
        $postRefundItem = $this->orderItem(
            11,
            5,
            'simple',
            null,
            '1.0000',
            '1.0000',
            '100.0000'
        );
        $postRefundItem->method('getAmountRefunded')->willReturn('100.0000');
        $postRefundItem->method('getBaseAmountRefunded')->willReturn('100.0000');
        $order->method('getItems')->willReturn([$postRefundItem]);

        $this->validator()->assertPersisted(
            $creditmemo,
            $order,
            'EGP',
            'EGP',
            '100.0000',
            new Plan([11 => '1.0000'], [], [])
        );

        self::assertTrue(true);
    }

    public function testFinalCompensationDeltaSubtractsPriorRefundedAmount(): void
    {
        $orderItem = $this->orderItem(
            11,
            5,
            'simple',
            null,
            '1.0000',
            '0.0000',
            '100.0000',
            '0.0000',
            '0.4000'
        );
        $order = $this->order([$orderItem]);
        $order->method('getDiscountTaxCompensationInvoiced')->willReturn('1.0000');
        $order->method('getDiscountTaxCompensationRefunded')->willReturn('0.5000');
        $order->method('getBaseDiscountTaxCompensationInvoiced')->willReturn('1.0000');
        $order->method('getBaseDiscountTaxCompensationRefunded')->willReturn('0.5000');
        $creditmemo = $this->creditmemo(
            '101.0000',
            '100.0000',
            [$this->creditmemoItem(11, '1.0000', '100.0000', '0.0000', '0.4000')]
        );
        $creditmemo->method('getDiscountTaxCompensationAmount')->willReturn('1.0000');
        $creditmemo->method('getBaseDiscountTaxCompensationAmount')->willReturn('1.0000');
        $this->expectException(InvariantViolationException::class);

        $this->validator()->assertPreview(
            $creditmemo,
            $order,
            'EGP',
            'EGP',
            '100.5000',
            new Plan([11 => '1.0000'], [], [])
        );
    }

    public function testRestockPlanRequiresNativeBackToStockFlag(): void
    {
        [$creditmemo, $order] = $this->simpleDocument('100.0000', '100.0000');
        $this->expectException(InvariantViolationException::class);

        $this->validator()->assertPreview(
            $creditmemo,
            $order,
            'EGP',
            'EGP',
            '100.0000',
            new Plan([11 => '1.0000'], [], [11])
        );
    }

    public function testQuarantinePlanRejectsForcedBackToStockFlag(): void
    {
        $orderItem = $this->orderItem(
            11,
            5,
            'simple',
            null,
            '1.0000',
            '0.0000',
            '100.0000'
        );
        $order = $this->order([$orderItem]);
        $creditmemo = $this->creditmemo(
            '100.0000',
            '100.0000',
            [$this->creditmemoItem(11, '1.0000', '100.0000', '0.0000', '0.0000', true)]
        );
        $this->expectException(InvariantViolationException::class);

        $this->validator()->assertPreview(
            $creditmemo,
            $order,
            'EGP',
            'EGP',
            '100.0000',
            new Plan([11 => '1.0000'], [], [])
        );
    }

    public function testConfigurableGeneratedChildCannotBeReturnedToStockDirectly(): void
    {
        $parent = $this->orderItem(
            11,
            5,
            'configurable',
            null,
            '1.0000',
            '0.0000',
            '100.0000'
        );
        $child = $this->orderItem(12, 5, 'simple', 11, '1.0000', '0.0000');
        $order = $this->order([$parent, $child]);
        $creditmemo = $this->creditmemo(
            '100.0000',
            '100.0000',
            [
                $this->creditmemoItem(
                    11,
                    '1.0000',
                    '100.0000',
                    '0.0000',
                    '0.0000',
                    true
                ),
                $this->creditmemoItem(
                    12,
                    '1.0000',
                    '0.0000',
                    '0.0000',
                    '0.0000',
                    true
                ),
            ]
        );
        $this->expectException(InvariantViolationException::class);

        $this->validator()->assertPreview(
            $creditmemo,
            $order,
            'EGP',
            'EGP',
            '100.0000',
            new Plan([11 => '1.0000'], [], [11])
        );
    }

    public function testValidatorRejectsDuplicateOrderItemEvenWhenDuplicateQtyIsZero(): void
    {
        $order = $this->order([
            $this->orderItem(11, 5, 'simple', null, '1.0000', '0.0000', '100.0000'),
        ]);
        $creditmemo = $this->creditmemo(
            '100.0000',
            '100.0000',
            [
                $this->creditmemoItem(11, '1.0000', '100.0000'),
                $this->creditmemoItem(11, '0.0000'),
            ]
        );
        $this->expectException(InvariantViolationException::class);

        $this->validator()->assertPreview(
            $creditmemo,
            $order,
            'EGP',
            'EGP',
            '100.0000',
            new Plan([11 => '1.0000'], [], [])
        );
    }

    public function testValidatorRejectsFinancialValueOnUnrelatedZeroQtyRow(): void
    {
        $order = $this->order([
            $this->orderItem(11, 5, 'simple', null, '1.0000', '0.0000', '100.0000'),
            $this->orderItem(22, 5, 'simple', null, '1.0000', '0.0000', '20.0000'),
        ]);
        $creditmemo = $this->creditmemo(
            '101.0000',
            '101.0000',
            [
                $this->creditmemoItem(11, '1.0000', '100.0000'),
                $this->creditmemoItem(22, '0.0000', '1.0000'),
            ]
        );
        $this->expectException(InvariantViolationException::class);

        $this->validator()->assertPreview(
            $creditmemo,
            $order,
            'EGP',
            'EGP',
            '101.0000',
            new Plan([11 => '1.0000'], [], [])
        );
    }

    public function testValidatorRejectsInflatedPartialComponentWithinFullRemainder(): void
    {
        $order = $this->order([
            $this->orderItem(11, 5, 'simple', null, '10.0000', '0.0000', '100.0000'),
        ]);
        $creditmemo = $this->creditmemo(
            '100.0000',
            '100.0000',
            [$this->creditmemoItem(11, '1.0000', '100.0000')]
        );
        $this->expectException(InvariantViolationException::class);

        $this->validator()->assertPreview(
            $creditmemo,
            $order,
            'EGP',
            'EGP',
            '10.0000',
            new Plan([11 => '1.0000'], [], [])
        );
    }

    public function testValidatorAcceptsCanonicalDeltaRoundedPartialComponents(): void
    {
        $order = $this->order([
            $this->orderItem(
                11,
                5,
                'simple',
                null,
                '3.0000',
                '0.0000',
                '100.0000',
                '10.0000',
                '1.0000'
            ),
        ]);
        $creditmemo = $this->creditmemo(
            '37.0000',
            '33.3300',
            [$this->creditmemoItem(11, '1.0000', '33.3300', '3.3400', '0.3300')]
        );
        $creditmemo->method('getTaxAmount')->willReturn('3.3400');
        $creditmemo->method('getBaseTaxAmount')->willReturn('3.3400');
        $creditmemo->method('getDiscountTaxCompensationAmount')->willReturn('0.3300');
        $creditmemo->method('getBaseDiscountTaxCompensationAmount')->willReturn('0.3300');

        $this->validator()->assertPreview(
            $creditmemo,
            $order,
            'EGP',
            'EGP',
            '37.0000',
            new Plan([11 => '1.0000'], [], [])
        );

        self::assertTrue(true);
    }

    public function testValidatorRejectsChoosingCeilingForEveryPartialComponent(): void
    {
        $order = $this->order([
            $this->orderItem(
                11,
                5,
                'simple',
                null,
                '3.0000',
                '0.0000',
                '100.0000',
                '10.0000',
                '1.0000'
            ),
        ]);
        $creditmemo = $this->creditmemo(
            '37.0200',
            '33.3400',
            [$this->creditmemoItem(11, '1.0000', '33.3400', '3.3400', '0.3400')]
        );
        $creditmemo->method('getTaxAmount')->willReturn('3.3400');
        $creditmemo->method('getBaseTaxAmount')->willReturn('3.3400');
        $creditmemo->method('getDiscountTaxCompensationAmount')->willReturn('0.3400');
        $creditmemo->method('getBaseDiscountTaxCompensationAmount')->willReturn('0.3400');
        $this->expectException(InvariantViolationException::class);

        $this->validator()->assertPreview(
            $creditmemo,
            $order,
            'EGP',
            'EGP',
            '37.0200',
            new Plan([11 => '1.0000'], [], [])
        );
    }

    public function testValidatorAcceptsCanonicalPartialDiscountDeltaRounding(): void
    {
        $order = $this->order([
            $this->orderItem(
                11,
                5,
                'simple',
                null,
                '3.0000',
                '0.0000',
                '100.0000',
                '10.0000',
                '1.0000',
                '0.0000',
                '5.0000'
            ),
        ]);
        $creditmemo = $this->creditmemo(
            '35.3300',
            '33.3300',
            [
                $this->creditmemoItem(
                    11,
                    '1.0000',
                    '33.3300',
                    '3.3300',
                    '0.3300',
                    false,
                    '0.0000',
                    '1.6600'
                ),
            ]
        );
        $creditmemo->method('getDiscountAmount')->willReturn('-1.6600');
        $creditmemo->method('getBaseDiscountAmount')->willReturn('-1.6600');
        $creditmemo->method('getTaxAmount')->willReturn('3.3300');
        $creditmemo->method('getBaseTaxAmount')->willReturn('3.3300');
        $creditmemo->method('getDiscountTaxCompensationAmount')->willReturn('0.3300');
        $creditmemo->method('getBaseDiscountTaxCompensationAmount')->willReturn('0.3300');

        $this->validator()->assertPreview(
            $creditmemo,
            $order,
            'EGP',
            'EGP',
            '35.3300',
            new Plan([11 => '1.0000'], [], [])
        );

        self::assertTrue(true);
    }

    public function testPersistedDocumentMustExactlyMatchCanonicalPreviewSnapshot(): void
    {
        $order = $this->order([
            $this->orderItem(
                11,
                5,
                'simple',
                null,
                '3.0000',
                '0.0000',
                '100.0000',
                '10.0000',
                '1.0000'
            ),
        ]);
        $preview = $this->creditmemo(
            '37.0000',
            '33.3300',
            [$this->creditmemoItem(11, '1.0000', '33.3300', '3.3400', '0.3300')]
        );
        $preview->method('getTaxAmount')->willReturn('3.3400');
        $preview->method('getBaseTaxAmount')->willReturn('3.3400');
        $preview->method('getDiscountTaxCompensationAmount')->willReturn('0.3300');
        $preview->method('getBaseDiscountTaxCompensationAmount')->willReturn('0.3300');
        $persisted = $this->creditmemo(
            '37.0000',
            '33.3400',
            [$this->creditmemoItem(11, '1.0000', '33.3400', '3.3300', '0.3300')]
        );
        $persisted->method('getTaxAmount')->willReturn('3.3300');
        $persisted->method('getBaseTaxAmount')->willReturn('3.3300');
        $persisted->method('getDiscountTaxCompensationAmount')->willReturn('0.3300');
        $persisted->method('getBaseDiscountTaxCompensationAmount')->willReturn('0.3300');
        $plan = new Plan([11 => '1.0000'], [], []);
        $validator = $this->validator();
        $validator->assertPreview($preview, $order, 'EGP', 'EGP', '37.0000', $plan);
        $validator->assertPreview($persisted, $order, 'EGP', 'EGP', '37.0000', $plan);
        $snapshot = $validator->executionSnapshot($preview);
        $fingerprint = $validator->persistentFingerprint($preview);
        self::assertNotSame(
            $fingerprint,
            $validator->persistentFingerprint($persisted)
        );
        try {
            $validator->assertPersistentFingerprint($persisted, $fingerprint);
            self::fail('A redistributed persisted credit memo must fail its fingerprint.');
        } catch (InvariantViolationException $exception) {
            self::assertStringContainsString(
                'fingerprint',
                $exception->getMessage()
            );
        }
        $this->expectException(InvariantViolationException::class);

        $validator->assertExecutionSnapshot($persisted, $snapshot);
    }

    public function testPersistentFingerprintExcludesTransientAndDeletedZeroRows(): void
    {
        $withTransientRows = $this->creditmemo(
            '100.0000',
            '100.0000',
            [
                $this->creditmemoItem(
                    11,
                    '1.0000',
                    '100.0000',
                    '0.0000',
                    '0.0000',
                    true
                ),
                $this->creditmemoItem(22, '0.0000'),
            ]
        );
        $persistedShape = $this->creditmemo(
            '100.0000',
            '100.0000',
            [$this->creditmemoItem(11, '1.0000', '100.0000')]
        );
        $validator = $this->validator();

        self::assertNotSame(
            $validator->executionSnapshot($withTransientRows),
            $validator->executionSnapshot($persistedShape)
        );
        self::assertSame(
            $validator->persistentFingerprint($withTransientRows),
            $validator->persistentFingerprint($persistedShape)
        );
    }

    public function testValidatorRejectsFinancialValueOnConfigurableChild(): void
    {
        $parent = $this->orderItem(
            11,
            5,
            'configurable',
            null,
            '1.0000',
            '0.0000',
            '100.0000'
        );
        $child = $this->orderItem(12, 5, 'simple', 11, '1.0000', '0.0000');
        $creditmemo = $this->creditmemo(
            '100.0100',
            '100.0100',
            [
                $this->creditmemoItem(11, '1.0000', '100.0000'),
                $this->creditmemoItem(12, '1.0000', '0.0100'),
            ]
        );
        $this->expectException(InvariantViolationException::class);

        $this->validator()->assertPreview(
            $creditmemo,
            $this->order([$parent, $child]),
            'EGP',
            'EGP',
            '100.0100',
            new Plan([11 => '1.0000'], [], [])
        );
    }

    public function testValidatorRejectsChangedItemBaseCostSnapshot(): void
    {
        $order = $this->order([
            $this->orderItem(
                11,
                5,
                'simple',
                null,
                '1.0000',
                '0.0000',
                '100.0000',
                '0.0000',
                '0.0000',
                '25.0000'
            ),
        ]);
        $creditmemo = $this->creditmemo(
            '100.0000',
            '100.0000',
            [$this->creditmemoItem(11, '1.0000', '100.0000', '0.0000', '0.0000', false, '26.0000')]
        );
        $this->expectException(InvariantViolationException::class);

        $this->validator()->assertPreview(
            $creditmemo,
            $order,
            'EGP',
            'EGP',
            '100.0000',
            new Plan([11 => '1.0000'], [], [])
        );
    }

    private function planner(): Planner
    {
        return new Planner(
            new ReturnableOrderItemValidator(),
            $this->quantityMath
        );
    }

    /**
     * @param OrderItemInterface[] $items
     */
    private function order(array $items): OrderInterface
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(5);
        $order->method('getItems')->willReturn($items);

        return $order;
    }

    private function projection(): ReturnCreditProjection
    {
        return new ReturnCreditProjection(
            $this->moneyMath,
            $this->quantityMath,
            new FinancialRowCalculator($this->moneyMath, $this->quantityMath)
        );
    }

    private function validator(): DocumentValidator
    {
        return new DocumentValidator($this->moneyMath, $this->quantityMath);
    }

    /**
     * @return array{CreditmemoInterface, OrderInterface}
     */
    private function simpleDocument(string $grandTotal, string $subtotal): array
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(5);
        $order->method('getItems')->willReturn([
            $this->orderItem(
                11,
                5,
                'simple',
                null,
                '1.0000',
                '0.0000',
                $subtotal
            ),
        ]);

        return [
            $this->creditmemo(
                $grandTotal,
                $subtotal,
                [$this->creditmemoItem(11, '1.0000', $subtotal)]
            ),
            $order,
        ];
    }

    /**
     * @param CreditmemoItemInterface[] $items
     */
    private function creditmemo(
        string $grandTotal,
        string $subtotal,
        array $items
    ): CreditmemoInterface {
        $creditmemo = $this->createMock(CreditmemoInterface::class);
        $creditmemo->method('getOrderId')->willReturn(5);
        $creditmemo->method('getOrderCurrencyCode')->willReturn('EGP');
        $creditmemo->method('getBaseCurrencyCode')->willReturn('EGP');
        $creditmemo->method('getGrandTotal')->willReturn($grandTotal);
        $creditmemo->method('getBaseGrandTotal')->willReturn($grandTotal);
        $creditmemo->method('getSubtotal')->willReturn($subtotal);
        $creditmemo->method('getBaseSubtotal')->willReturn($subtotal);
        $creditmemo->method('getItems')->willReturn($items);

        return $creditmemo;
    }

    private function creditmemoItem(
        int $orderItemId,
        string $quantity,
        string $rowTotal = '0.0000',
        string $tax = '0.0000',
        string $compensation = '0.0000',
        bool $backToStock = false,
        string $baseCost = '0.0000',
        string $discount = '0.0000'
    ): CreditmemoItemInterface {
        $item = (new \ReflectionClass(CreditmemoItem::class))->newInstanceWithoutConstructor();
        $item->setData([
            CreditmemoItemInterface::ORDER_ITEM_ID => $orderItemId,
            CreditmemoItemInterface::QTY => $quantity,
            CreditmemoItemInterface::ROW_TOTAL => $rowTotal,
            CreditmemoItemInterface::BASE_ROW_TOTAL => $rowTotal,
            CreditmemoItemInterface::TAX_AMOUNT => $tax,
            CreditmemoItemInterface::BASE_TAX_AMOUNT => $tax,
            CreditmemoItemInterface::DISCOUNT_AMOUNT => $discount,
            CreditmemoItemInterface::BASE_DISCOUNT_AMOUNT => $discount,
            CreditmemoItemInterface::DISCOUNT_TAX_COMPENSATION_AMOUNT => $compensation,
            CreditmemoItemInterface::BASE_DISCOUNT_TAX_COMPENSATION_AMOUNT => $compensation,
            CreditmemoItemInterface::BASE_COST => $baseCost,
            'back_to_stock' => $backToStock,
        ]);

        return $item;
    }

    private function orderItem(
        int $itemId,
        int $orderId,
        string $productType,
        ?int $parentItemId,
        string $invoiced,
        string $refunded,
        string $rowInvoiced = '0.0000',
        string $taxInvoiced = '0.0000',
        string $compensationInvoiced = '0.0000',
        string $baseCost = '0.0000',
        string $discountInvoiced = '0.0000'
    ): OrderItemInterface {
        $item = $this->createMock(OrderItemInterface::class);
        $item->method('getItemId')->willReturn($itemId);
        $item->method('getOrderId')->willReturn($orderId);
        $item->method('getProductType')->willReturn($productType);
        $item->method('getParentItemId')->willReturn($parentItemId);
        $item->method('getQtyInvoiced')->willReturn($invoiced);
        $item->method('getQtyRefunded')->willReturn($refunded);
        $item->method('getName')->willReturn('Returned item');
        $item->method('getRowInvoiced')->willReturn($rowInvoiced);
        $item->method('getBaseRowInvoiced')->willReturn($rowInvoiced);
        $item->method('getAmountRefunded')->willReturn('0.0000');
        $item->method('getBaseAmountRefunded')->willReturn('0.0000');
        $item->method('getTaxInvoiced')->willReturn($taxInvoiced);
        $item->method('getBaseTaxInvoiced')->willReturn($taxInvoiced);
        $item->method('getTaxRefunded')->willReturn('0.0000');
        $item->method('getBaseTaxRefunded')->willReturn('0.0000');
        $item->method('getDiscountInvoiced')->willReturn($discountInvoiced);
        $item->method('getBaseDiscountInvoiced')->willReturn($discountInvoiced);
        $item->method('getDiscountRefunded')->willReturn('0.0000');
        $item->method('getBaseDiscountRefunded')->willReturn('0.0000');
        $item->method('getDiscountTaxCompensationInvoiced')
            ->willReturn($compensationInvoiced);
        $item->method('getBaseDiscountTaxCompensationInvoiced')
            ->willReturn($compensationInvoiced);
        $item->method('getDiscountTaxCompensationRefunded')->willReturn('0.0000');
        $item->method('getBaseDiscountTaxCompensationRefunded')->willReturn('0.0000');
        $item->method('getBaseCost')->willReturn($baseCost);

        return $item;
    }
}
