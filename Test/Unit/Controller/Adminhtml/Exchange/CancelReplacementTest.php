<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Unit\Controller\Adminhtml\Exchange;

use Bonlineco\SalesExchange\Api\CancelReplacementIntentInterface;
use Bonlineco\SalesExchange\Api\Data\ExchangeInterface;
use Bonlineco\SalesExchange\Controller\Adminhtml\Exchange\CancelReplacement;
use Bonlineco\SalesExchange\Model\Authorization\ReplacementCancellationAuthorization;
use Bonlineco\SalesExchange\Test\Unit\Fixture\AuthSessionStub;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Backend\Model\View\Result\RedirectFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Stdlib\StringUtils;
use Magento\User\Model\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Verify strict POST input and server-owned actor handling.
 */
class CancelReplacementTest extends TestCase
{
    private RequestInterface&MockObject $request;

    private CancelReplacementIntentInterface&MockObject $service;

    private Session&MockObject $authSession;

    private ManagerInterface&MockObject $messageManager;

    private Redirect&MockObject $redirect;

    private CancelReplacement $subject;

    protected function setUp(): void
    {
        $this->request = $this->createMock(RequestInterface::class);
        $this->service = $this->createMock(
            CancelReplacementIntentInterface::class
        );
        $this->authSession = $this->getMockBuilder(AuthSessionStub::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getUser'])
            ->getMock();
        $this->messageManager = $this->createMock(ManagerInterface::class);
        $this->redirect = $this->createMock(Redirect::class);
        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($this->redirect);
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($this->request);
        $context->method('getResultRedirectFactory')
            ->willReturn($redirectFactory);
        $context->method('getMessageManager')
            ->willReturn($this->messageManager);

        $this->subject = new CancelReplacement(
            $context,
            $this->service,
            $this->authSession,
            $this->createMock(
                ReplacementCancellationAuthorization::class
            ),
            new StringUtils(),
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testUsesAdminSessionActorAndNormalizedComment(): void
    {
        $this->setRequestParams([
            'entity_id' => '41',
            'version' => '7',
            'comment' => '  customer requested refund  ',
        ]);
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(9);
        $this->authSession->method('getUser')->willReturn($user);
        $exchange = $this->createMock(ExchangeInterface::class);
        $this->service->expects(self::once())
            ->method('execute')
            ->with(41, 7, 9, 'customer requested refund')
            ->willReturn($exchange);
        $this->messageManager->expects(self::once())
            ->method('addSuccessMessage');
        $this->redirect->expects(self::once())
            ->method('setPath')
            ->with('*/*/view', ['entity_id' => 41])
            ->willReturnSelf();

        self::assertSame($this->redirect, $this->subject->execute());
    }

    public function testRejectsBooleanIdentifierBeforeReadingAdminSession(): void
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

    public function testRejectsOversizedCommentBeforeCallingService(): void
    {
        $this->setRequestParams([
            'entity_id' => '41',
            'version' => '7',
            'comment' => str_repeat('x', 1001),
        ]);
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(9);
        $this->authSession->method('getUser')->willReturn($user);
        $this->service->expects(self::never())->method('execute');
        $this->messageManager->expects(self::once())
            ->method('addErrorMessage');
        $this->redirect->expects(self::once())
            ->method('setPath')
            ->with('*/*/view', ['entity_id' => 41])
            ->willReturnSelf();

        self::assertSame($this->redirect, $this->subject->execute());
    }

    public function testRejectsNonStringCommentBeforeCallingService(): void
    {
        $this->setRequestParams([
            'entity_id' => '41',
            'version' => '7',
            'comment' => true,
        ]);
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(9);
        $this->authSession->method('getUser')->willReturn($user);
        $this->service->expects(self::never())->method('execute');
        $this->messageManager->expects(self::once())
            ->method('addErrorMessage');
        $this->redirect->expects(self::once())
            ->method('setPath')
            ->with('*/*/view', ['entity_id' => 41])
            ->willReturnSelf();

        self::assertSame($this->redirect, $this->subject->execute());
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
