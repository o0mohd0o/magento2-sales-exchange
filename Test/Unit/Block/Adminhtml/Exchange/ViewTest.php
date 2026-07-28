<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Unit\Block\Adminhtml\Exchange;

use Bonlineco\SalesExchange\Api\ConfigInterface;
use Bonlineco\SalesExchange\Api\Data\DocumentLinkInterface;
use Bonlineco\SalesExchange\Api\Data\ExchangeInterface;
use Bonlineco\SalesExchange\Api\Data\ReplacementItemInterface;
use Bonlineco\SalesExchange\Api\Data\ReturnItemInterface;
use Bonlineco\SalesExchange\Api\DocumentLinkRepositoryInterface;
use Bonlineco\SalesExchange\Api\DocumentType;
use Bonlineco\SalesExchange\Api\Status\ExchangeStatus;
use Bonlineco\SalesExchange\Api\Status\ReplacementStatus;
use Bonlineco\SalesExchange\Api\Status\ReturnStatus;
use Bonlineco\SalesExchange\Api\Status\SettlementStatus;
use Bonlineco\SalesExchange\Block\Adminhtml\Exchange\View;
use Bonlineco\SalesExchange\Model\Authorization\ReplacementCancellationAuthorization;
use Bonlineco\SalesExchange\Model\Authorization\ReplacementOrderAuthorization;
use Bonlineco\SalesExchange\Model\Authorization\SettlementAuthorization;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Bonlineco\SalesExchange\Model\Settlement\OperationKeys;
use Bonlineco\SalesExchange\Model\Workflow\AdminActionMap;
use Magento\Backend\Block\Template;
use Magento\Framework\AuthorizationInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verify replacement-order admin controls use status, links, and ACLs.
 */
class ViewTest extends TestCase
{
    /**
     * @dataProvider actionableReplacementStatusProvider
     */
    #[DataProvider('actionableReplacementStatusProvider')]
    public function testCreateOrResumeRequiresTheCompleteWorkflowSnapshot(
        string $replacementStatus
    ): void {
        $subject = $this->newSubject();
        $exchange = $this->exchange($replacementStatus);
        $authorization = $this->createMock(ReplacementOrderAuthorization::class);
        $authorization->method('isAllowed')->willReturn(true);
        $config = $this->createMock(ConfigInterface::class);
        $config->method('isEnabled')->with(3)->willReturn(true);
        $this->setProperty($subject, 'exchange', $exchange);
        $this->setProperty($subject, 'exchangeLoaded', true);
        $this->setProperty($subject, 'replacementOrderAuthorization', $authorization);
        $this->setProperty($subject, 'config', $config);

        self::assertTrue($subject->canCreateReplacementOrder());
    }

    public function testOrderLinkRetrievalStopsBeforeRepositoryWhenViewAclIsMissing(): void
    {
        $subject = $this->newSubject();
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->expects(self::once())
            ->method('isAllowed')
            ->with(AdminActionMap::ACL_VIEW)
            ->willReturn(false);
        $repository = $this->createMock(DocumentLinkRepositoryInterface::class);
        $repository->expects(self::never())->method('getList');
        $this->setParentProperty($subject, '_authorization', $authorization);
        $this->setProperty($subject, 'documentLinkRepository', $repository);

        self::assertNull($subject->getReplacementOrderLink());
    }

    public function testZeroTotalLinkedOrderRemainsViewable(): void
    {
        $subject = $this->newSubject();
        $exchange = $this->exchange(ReplacementStatus::ORDERED);
        $documentLink = $this->createMock(DocumentLinkInterface::class);
        $documentLink->method('getDocumentType')->willReturn(DocumentType::ORDER);
        $documentLink->method('getDocumentId')->willReturn(52);
        $documentLink->expects(self::never())->method('getAmount');
        $this->setProperty($subject, 'exchange', $exchange);
        $this->setProperty($subject, 'exchangeLoaded', true);
        $this->setProperty($subject, 'replacementOrderLink', $documentLink);
        $this->setProperty($subject, 'replacementOrderLinkLoaded', true);

        self::assertTrue($subject->canViewReplacementOrder());
    }

    public function testVarianceUsesNativeActualMinusApprovedMerchandiseAndShipping(): void
    {
        $subject = $this->newSubject();
        $exchange = $this->exchange(ReplacementStatus::ORDERED);
        $exchange->method('getReplacementAmount')->willReturn('100.0000');
        $exchange->method('getShippingAmount')->willReturn('20.0000');
        $exchange->method('getNativeReplacementAmount')->willReturn('0.0000');
        $this->setProperty($subject, 'exchange', $exchange);
        $this->setProperty($subject, 'exchangeLoaded', true);
        $this->setProperty($subject, 'quantityMath', new DecimalMath());

        self::assertSame('-120.0000', $subject->getReplacementVariance());
    }

    public function testReadyUnplacedReplacementCanExposeDedicatedCancelAction(): void
    {
        $subject = $this->newSubject();
        $authorization = $this->createMock(
            ReplacementCancellationAuthorization::class
        );
        $authorization->method('isAllowed')->willReturn(true);
        $this->setProperty(
            $subject,
            'exchange',
            $this->exchange(ReplacementStatus::READY)
        );
        $this->setProperty($subject, 'exchangeLoaded', true);
        $this->setProperty(
            $subject,
            'replacementCancellationAuthorization',
            $authorization
        );
        $this->setProperty($subject, 'replacementOrderLink', null);
        $this->setProperty($subject, 'replacementOrderLinkLoaded', true);
        $this->setProperty($subject, 'replacementItems', []);
        $this->setProperty($subject, 'quantityMath', new DecimalMath());

        self::assertTrue($subject->canCancelReplacementIntent());
    }

    public function testNativeItemLinkHidesReplacementCancelAction(): void
    {
        $subject = $this->newSubject();
        $authorization = $this->createMock(
            ReplacementCancellationAuthorization::class
        );
        $authorization->method('isAllowed')->willReturn(true);
        $item = $this->createMock(ReplacementItemInterface::class);
        $item->method('getReplacementOrderItemId')->willReturn(501);
        $this->setProperty(
            $subject,
            'exchange',
            $this->exchange(ReplacementStatus::READY)
        );
        $this->setProperty($subject, 'exchangeLoaded', true);
        $this->setProperty(
            $subject,
            'replacementCancellationAuthorization',
            $authorization
        );
        $this->setProperty($subject, 'replacementOrderLink', null);
        $this->setProperty($subject, 'replacementOrderLinkLoaded', true);
        $this->setProperty($subject, 'replacementItems', [$item]);
        $this->setProperty($subject, 'quantityMath', new DecimalMath());

        self::assertFalse($subject->canCancelReplacementIntent());
    }

    public function testTrustedActiveReplacementCanExposeSettlementForm(): void
    {
        $subject = $this->newSubject();
        $exchange = $this->settlementExchange(
            ReplacementStatus::ORDERED,
            '120.0000',
            '100.0000',
            '20.0000'
        );
        $returnItem = $this->returnItem('1.0000', '1.0000');
        $orderLink = $this->documentLink(
            DocumentType::ORDER,
            (new OperationKeys())->replacementOrder(41)
        );
        $this->configureSettlementSubject(
            $subject,
            $exchange,
            [$returnItem],
            [$orderLink],
            [$this->replacementItem(501)]
        );

        self::assertTrue($subject->canReconcileSettlement());
        self::assertTrue($subject->settlementRequiresExternalReference());
    }

    public function testBalancedSettlementDoesNotRequestExternalReference(): void
    {
        $subject = $this->newSubject();
        $exchange = $this->settlementExchange(
            ReplacementStatus::DELIVERED,
            '100.0000',
            '100.0000',
            '0.0000'
        );
        $this->configureSettlementSubject(
            $subject,
            $exchange,
            [$this->returnItem('1.0000', '1.0000')],
            [
                $this->documentLink(
                    DocumentType::ORDER,
                    (new OperationKeys())->replacementOrder(41)
                ),
            ],
            [$this->replacementItem(501)]
        );

        self::assertTrue($subject->canReconcileSettlement());
        self::assertFalse($subject->settlementRequiresExternalReference());
    }

    public function testUncreditedAcceptedQuantityHidesSettlementAction(): void
    {
        $subject = $this->newSubject();
        $exchange = $this->settlementExchange(
            ReplacementStatus::ORDERED,
            '120.0000',
            '100.0000',
            '20.0000'
        );
        $this->configureSettlementSubject(
            $subject,
            $exchange,
            [$this->returnItem('2.0000', '1.0000')],
            [
                $this->documentLink(
                    DocumentType::ORDER,
                    (new OperationKeys())->replacementOrder(41)
                ),
            ],
            [$this->replacementItem(501)]
        );

        self::assertFalse($subject->canReconcileSettlement());
    }

    public function testUntrustedOrderLinkHidesSettlementAction(): void
    {
        $subject = $this->newSubject();
        $exchange = $this->settlementExchange(
            ReplacementStatus::SHIPPED,
            '120.0000',
            '100.0000',
            '20.0000'
        );
        $this->configureSettlementSubject(
            $subject,
            $exchange,
            [$this->returnItem('1.0000', '1.0000')],
            [$this->documentLink(DocumentType::ORDER, 'untrusted-operation')],
            [$this->replacementItem(501)]
        );

        self::assertFalse($subject->canReconcileSettlement());
    }

    public function testUnlinkedReplacementItemHidesSettlementAction(): void
    {
        $subject = $this->newSubject();
        $exchange = $this->settlementExchange(
            ReplacementStatus::ORDERED,
            '120.0000',
            '100.0000',
            '20.0000'
        );
        $this->configureSettlementSubject(
            $subject,
            $exchange,
            [$this->returnItem('1.0000', '1.0000')],
            [
                $this->documentLink(
                    DocumentType::ORDER,
                    (new OperationKeys())->replacementOrder(41)
                ),
            ],
            [$this->replacementItem(null)]
        );

        self::assertFalse($subject->canReconcileSettlement());
    }

    public function testCleanCancelledReplacementCanExposeRefundSettlement(): void
    {
        $subject = $this->newSubject();
        $exchange = $this->settlementExchange(
            ReplacementStatus::CANCELLED,
            '0.0000',
            '100.0000',
            '-100.0000'
        );
        $replacementItem = $this->createMock(ReplacementItemInterface::class);
        $replacementItem->method('getReplacementOrderItemId')->willReturn(null);
        $this->configureSettlementSubject(
            $subject,
            $exchange,
            [$this->returnItem('1.0000', '1.0000')],
            [],
            [$replacementItem]
        );

        self::assertTrue($subject->canReconcileSettlement());
        self::assertTrue($subject->settlementRequiresExternalReference());
    }

    public function testNativeCancelledReplacementRetainsTrustedOrderAudit(): void
    {
        $subject = $this->newSubject();
        $exchange = $this->settlementExchange(
            ReplacementStatus::CANCELLED,
            '0.0000',
            '100.0000',
            '-100.0000'
        );
        $this->configureSettlementSubject(
            $subject,
            $exchange,
            [$this->returnItem('1.0000', '1.0000')],
            [
                $this->documentLink(
                    DocumentType::ORDER,
                    (new OperationKeys())->replacementOrder(41)
                ),
            ],
            [$this->replacementItem(null)]
        );

        self::assertTrue($subject->canReconcileSettlement());
    }

    public function testNativeCancelledReplacementRejectsUntrustedOrderAudit(): void
    {
        $subject = $this->newSubject();
        $exchange = $this->settlementExchange(
            ReplacementStatus::CANCELLED,
            '0.0000',
            '100.0000',
            '-100.0000'
        );
        $this->configureSettlementSubject(
            $subject,
            $exchange,
            [$this->returnItem('1.0000', '1.0000')],
            [
                $this->documentLink(
                    DocumentType::ORDER,
                    'untrusted-operation'
                ),
            ],
            [$this->replacementItem(null)]
        );

        self::assertFalse($subject->canReconcileSettlement());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function actionableReplacementStatusProvider(): array
    {
        return [
            'create pending replacement' => [ReplacementStatus::PENDING],
            'resume ready replacement' => [ReplacementStatus::READY],
        ];
    }

    private function newSubject(): View
    {
        $reflection = new \ReflectionClass(View::class);
        /** @var View $subject */
        $subject = $reflection->newInstanceWithoutConstructor();

        return $subject;
    }

    private function exchange(string $replacementStatus): ExchangeInterface
    {
        $exchange = $this->createMock(ExchangeInterface::class);
        $exchange->method('getStoreId')->willReturn(3);
        $exchange->method('getExchangeStatus')->willReturn(ExchangeStatus::IN_PROGRESS);
        $exchange->method('getReturnStatus')->willReturn(ReturnStatus::ACCEPTED);
        $exchange->method('getReplacementStatus')->willReturn($replacementStatus);
        $exchange->method('getSettlementStatus')->willReturn(SettlementStatus::PENDING);
        $exchange->method('getNativeReplacementAmount')->willReturn('0.0000');
        $exchange->method('getBaseNativeReplacementAmount')->willReturn('0.0000');

        return $exchange;
    }

    private function settlementExchange(
        string $replacementStatus,
        string $nativeReplacementAmount,
        string $nativeReturnCreditAmount,
        string $balanceAmount
    ): ExchangeInterface {
        $exchange = $this->createMock(ExchangeInterface::class);
        $exchange->method('getEntityId')->willReturn(41);
        $exchange->method('getStoreId')->willReturn(3);
        $exchange->method('getExchangeStatus')
            ->willReturn(ExchangeStatus::IN_PROGRESS);
        $exchange->method('getReturnStatus')->willReturn(ReturnStatus::ACCEPTED);
        $exchange->method('getReplacementStatus')
            ->willReturn($replacementStatus);
        $exchange->method('getSettlementStatus')
            ->willReturn(SettlementStatus::PENDING);
        $exchange->method('getFeeAmount')->willReturn('0.0000');
        $exchange->method('getNativeReturnCreditAmount')
            ->willReturn($nativeReturnCreditAmount);
        $exchange->method('getNativeReplacementAmount')
            ->willReturn($nativeReplacementAmount);
        $exchange->method('getBaseNativeReplacementAmount')
            ->willReturn($nativeReplacementAmount);
        $exchange->method('getBalanceAmount')->willReturn($balanceAmount);

        return $exchange;
    }

    private function returnItem(
        string $acceptedQuantity,
        string $creditedQuantity
    ): ReturnItemInterface {
        $item = $this->createMock(ReturnItemInterface::class);
        $item->method('getAcceptedQty')->willReturn($acceptedQuantity);
        $item->method('getCreditedQty')->willReturn($creditedQuantity);

        return $item;
    }

    private function documentLink(
        string $documentType,
        string $operationKey
    ): DocumentLinkInterface {
        $documentLink = $this->createMock(DocumentLinkInterface::class);
        $documentLink->method('getDocumentType')->willReturn($documentType);
        $documentLink->method('getDocumentId')->willReturn(52);
        $documentLink->method('getOperationKey')->willReturn($operationKey);

        return $documentLink;
    }

    private function replacementItem(
        ?int $replacementOrderItemId
    ): ReplacementItemInterface {
        $item = $this->createMock(ReplacementItemInterface::class);
        $item->method('getReplacementOrderItemId')
            ->willReturn($replacementOrderItemId);

        return $item;
    }

    /**
     * @param ReturnItemInterface[] $returnItems
     * @param DocumentLinkInterface[] $documentLinks
     * @param ReplacementItemInterface[] $replacementItems
     */
    private function configureSettlementSubject(
        View $subject,
        ExchangeInterface $exchange,
        array $returnItems,
        array $documentLinks,
        array $replacementItems = []
    ): void {
        $authorization = $this->createMock(SettlementAuthorization::class);
        $authorization->method('isAllowed')->willReturn(true);
        $config = $this->createMock(ConfigInterface::class);
        $config->method('isEnabled')->with(3)->willReturn(true);
        $this->setProperty($subject, 'exchange', $exchange);
        $this->setProperty($subject, 'exchangeLoaded', true);
        $this->setProperty(
            $subject,
            'settlementAuthorization',
            $authorization
        );
        $this->setProperty($subject, 'config', $config);
        $this->setProperty($subject, 'quantityMath', new DecimalMath());
        $this->setProperty(
            $subject,
            'settlementOperationKeys',
            new OperationKeys()
        );
        $this->setProperty($subject, 'returnItems', $returnItems);
        $this->setProperty($subject, 'replacementItems', $replacementItems);
        $this->setProperty(
            $subject,
            'settlementDocumentLinks',
            $documentLinks
        );
    }

    private function setProperty(View $subject, string $propertyName, mixed $value): void
    {
        $property = new \ReflectionProperty(View::class, $propertyName);
        $property->setValue($subject, $value);
    }

    private function setParentProperty(View $subject, string $propertyName, mixed $value): void
    {
        $property = new \ReflectionProperty(Template::class, $propertyName);
        $property->setValue($subject, $value);
    }
}
