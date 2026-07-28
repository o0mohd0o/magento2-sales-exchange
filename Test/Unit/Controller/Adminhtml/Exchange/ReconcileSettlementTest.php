<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Unit\Controller\Adminhtml\Exchange;

use Bonlineco\SalesExchange\Api\Data\ExchangeInterface;
use Bonlineco\SalesExchange\Api\ReconcileSettlementInterface;
use Bonlineco\SalesExchange\Controller\Adminhtml\Exchange\ReconcileSettlement;
use Bonlineco\SalesExchange\Model\Authorization\SettlementAuthorization;
use Bonlineco\SalesExchange\Test\Unit\Fixture\AuthSessionStub;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Backend\Model\View\Result\RedirectFactory;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Stdlib\StringUtils;
use Magento\User\Model\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Verify strict settlement input and server-owned command fields.
 */
class ReconcileSettlementTest extends TestCase
{
    private RequestInterface&MockObject $request;

    private ReconcileSettlementInterface&MockObject $service;

    private Session&MockObject $authSession;

    private SettlementAuthorization&MockObject $authorization;

    private ManagerInterface&MockObject $messageManager;

    private Redirect&MockObject $redirect;

    private LoggerInterface&MockObject $logger;

    private ReconcileSettlement $subject;

    protected function setUp(): void
    {
        $this->request = $this->createMock(RequestInterface::class);
        $this->service = $this->createMock(
            ReconcileSettlementInterface::class
        );
        $this->authSession = $this->getMockBuilder(AuthSessionStub::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getUser'])
            ->getMock();
        $this->authorization = $this->createMock(
            SettlementAuthorization::class
        );
        $this->messageManager = $this->createMock(ManagerInterface::class);
        $this->redirect = $this->createMock(Redirect::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($this->redirect);
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($this->request);
        $context->method('getResultRedirectFactory')
            ->willReturn($redirectFactory);
        $context->method('getMessageManager')
            ->willReturn($this->messageManager);

        $this->subject = new ReconcileSettlement(
            $context,
            $this->service,
            $this->authSession,
            $this->authorization,
            new StringUtils(),
            $this->logger
        );
    }

    public function testUsesServerOwnedActorAndIgnoresFinancialRequestFields(): void
    {
        $this->setRequestParams([
            'entity_id' => '41',
            'version' => '7',
            'external_reference' => '  PSP-9981  ',
            'comment' => '  reconciled by finance  ',
            'amount' => '0.01',
            'type' => 'merchant_refund',
            'settlement_status' => 'failed',
        ]);
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(9);
        $this->authSession->method('getUser')->willReturn($user);
        $exchange = $this->createMock(ExchangeInterface::class);
        $this->service->expects(self::once())
            ->method('execute')
            ->with(41, 7, 9, 'PSP-9981', 'reconciled by finance')
            ->willReturn($exchange);
        $this->messageManager->expects(self::once())
            ->method('addSuccessMessage');
        $this->logger->expects(self::never())->method('critical');
        $this->redirect->expects(self::once())
            ->method('setPath')
            ->with('*/*/view', ['entity_id' => 41])
            ->willReturnSelf();

        self::assertSame($this->redirect, $this->subject->execute());
    }

    public function testRejectsBooleanEntityIdBeforeReadingAdminSession(): void
    {
        $this->setRequestParams([
            'entity_id' => true,
            'version' => '7',
        ]);
        $this->service->expects(self::never())->method('execute');
        $this->authSession->expects(self::never())->method('getUser');
        $this->messageManager->expects(self::once())
            ->method('addErrorMessage');
        $this->redirect->expects(self::once())
            ->method('setPath')
            ->with('*/*/index')
            ->willReturnSelf();

        self::assertSame($this->redirect, $this->subject->execute());
    }

    public function testRejectsNonIntegerVersionBeforeReadingAdminSession(): void
    {
        $this->setRequestParams([
            'entity_id' => '41',
            'version' => '7.0',
        ]);
        $this->service->expects(self::never())->method('execute');
        $this->authSession->expects(self::never())->method('getUser');
        $this->messageManager->expects(self::once())
            ->method('addErrorMessage');
        $this->redirect->expects(self::once())
            ->method('setPath')
            ->with('*/*/view', ['entity_id' => 41])
            ->willReturnSelf();

        self::assertSame($this->redirect, $this->subject->execute());
    }

    /**
     * @dataProvider invalidOptionalInputProvider
     * @param array<string, mixed> $optionalInput
     */
    #[DataProvider('invalidOptionalInputProvider')]
    public function testRejectsInvalidOptionalInput(
        array $optionalInput
    ): void {
        $this->setRequestParams(array_merge(
            [
                'entity_id' => '41',
                'version' => '7',
            ],
            $optionalInput
        ));
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(9);
        $this->authSession->method('getUser')->willReturn($user);
        $this->service->expects(self::never())->method('execute');
        $this->messageManager->expects(self::once())
            ->method('addErrorMessage');
        $this->logger->expects(self::never())->method('critical');
        $this->redirect->expects(self::once())
            ->method('setPath')
            ->with('*/*/view', ['entity_id' => 41])
            ->willReturnSelf();

        self::assertSame($this->redirect, $this->subject->execute());
    }

    public function testRejectsExpiredAdminSession(): void
    {
        $this->setRequestParams([
            'entity_id' => '41',
            'version' => '7',
        ]);
        $this->authSession->method('getUser')->willReturn(null);
        $this->service->expects(self::never())->method('execute');
        $this->messageManager->expects(self::once())
            ->method('addErrorMessage');
        $this->logger->expects(self::never())->method('critical');
        $this->redirect->expects(self::once())
            ->method('setPath')
            ->with('*/*/view', ['entity_id' => 41])
            ->willReturnSelf();

        self::assertSame($this->redirect, $this->subject->execute());
    }

    public function testLogsGenericFailureWithoutExposingItsMessage(): void
    {
        $this->setRequestParams([
            'entity_id' => '41',
            'version' => '7',
        ]);
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(9);
        $this->authSession->method('getUser')->willReturn($user);
        $this->service->method('execute')->willThrowException(
            new \RuntimeException('sensitive infrastructure detail')
        );
        $this->logger->expects(self::once())
            ->method('critical')
            ->with(
                'Exchange settlement reconciliation failed.',
                self::callback(
                    static fn (array $context): bool =>
                        $context['exchange_id'] === 41
                        && $context['exception'] instanceof \RuntimeException
                )
            );
        $this->messageManager->expects(self::once())
            ->method('addErrorMessage')
            ->with(self::callback(
                static fn ($message): bool =>
                    !str_contains(
                        (string)$message,
                        'sensitive infrastructure detail'
                    )
            ));
        $this->redirect->expects(self::once())
            ->method('setPath')
            ->with('*/*/view', ['entity_id' => 41])
            ->willReturnSelf();

        self::assertSame($this->redirect, $this->subject->execute());
    }

    public function testDeclaresPostAndDedicatedAclBoundary(): void
    {
        self::assertInstanceOf(HttpPostActionInterface::class, $this->subject);
        self::assertSame(
            SettlementAuthorization::ACL_SETTLEMENT,
            ReconcileSettlement::ADMIN_RESOURCE
        );
        $this->authorization->expects(self::once())
            ->method('isAllowed')
            ->willReturn(true);
        $method = new \ReflectionMethod($this->subject, '_isAllowed');

        self::assertTrue($method->invoke($this->subject));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidOptionalInputProvider(): array
    {
        return [
            'reference is not a string' => [[
                'external_reference' => ['not', 'a', 'string'],
            ]],
            'reference is too long' => [[
                'external_reference' => str_repeat('r', 256),
            ]],
            'comment is not a string' => [[
                'comment' => true,
            ]],
            'comment is too long' => [[
                'comment' => str_repeat('c', 1001),
            ]],
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function setRequestParams(array $params): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static fn (string $name) => $params[$name] ?? null
        );
    }
}
