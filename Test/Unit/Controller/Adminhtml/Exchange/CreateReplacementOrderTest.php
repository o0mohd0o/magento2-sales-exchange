<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Unit\Controller\Adminhtml\Exchange;

use Bonlineco\SalesExchange\Api\CreateReplacementOrderInterface;
use Bonlineco\SalesExchange\Api\Data\DocumentLinkInterface;
use Bonlineco\SalesExchange\Controller\Adminhtml\Exchange\CreateReplacementOrder;
use Bonlineco\SalesExchange\Model\Authorization\ReplacementOrderAuthorization;
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
 * Verify strict admin input handling and safe replacement-order responses.
 */
class CreateReplacementOrderTest extends TestCase
{
    private RequestInterface&MockObject $request;

    private CreateReplacementOrderInterface&MockObject $service;

    private Session&MockObject $authSession;

    private ManagerInterface&MockObject $messageManager;

    private Redirect&MockObject $redirect;

    private LoggerInterface&MockObject $logger;

    private CreateReplacementOrder $subject;

    protected function setUp(): void
    {
        $this->request = $this->createMock(RequestInterface::class);
        $this->service = $this->createMock(CreateReplacementOrderInterface::class);
        $this->authSession = $this->getMockBuilder(AuthSessionStub::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getUser'])
            ->getMock();
        $this->messageManager = $this->createMock(ManagerInterface::class);
        $this->redirect = $this->createMock(Redirect::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($this->redirect);
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($this->request);
        $context->method('getResultRedirectFactory')->willReturn($redirectFactory);
        $context->method('getMessageManager')->willReturn($this->messageManager);

        $this->subject = new CreateReplacementOrder(
            $context,
            $this->service,
            $this->authSession,
            $this->createMock(ReplacementOrderAuthorization::class),
            new StringUtils(),
            $this->logger
        );
    }

    public function testExecutesWithServerOwnedActorAndRedirectsToExchange(): void
    {
        $this->setRequestParams([
            'entity_id' => '41',
            'version' => '7',
            'comment' => '  approved by operations  ',
        ]);
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(9);
        $this->authSession->method('getUser')->willReturn($user);
        $documentLink = $this->createMock(DocumentLinkInterface::class);
        $documentLink->method('getIncrementId')->willReturn('000000321');
        $this->service->expects(self::once())
            ->method('execute')
            ->with(41, 7, 9, 'approved by operations')
            ->willReturn($documentLink);
        $this->messageManager->expects(self::once())
            ->method('addSuccessMessage')
            ->with(self::callback(
                static fn ($message): bool =>
                    str_contains((string)$message, '000000321')
            ));
        $this->logger->expects(self::never())->method('critical');
        $this->redirect->expects(self::once())
            ->method('setPath')
            ->with('*/*/view', ['entity_id' => 41])
            ->willReturnSelf();

        self::assertSame($this->redirect, $this->subject->execute());
    }

    public function testRejectsNonIntegerEntityIdWithoutCallingService(): void
    {
        $this->setRequestParams([
            'entity_id' => '4.1',
            'version' => '7',
        ]);
        $this->service->expects(self::never())->method('execute');
        $this->authSession->expects(self::never())->method('getUser');
        $this->messageManager->expects(self::once())->method('addErrorMessage');
        $this->logger->expects(self::never())->method('critical');
        $this->redirect->expects(self::once())
            ->method('setPath')
            ->with('*/*/index')
            ->willReturnSelf();

        self::assertSame($this->redirect, $this->subject->execute());
    }

    public function testRejectsBooleanEntityIdWithoutCallingService(): void
    {
        $this->setRequestParams([
            'entity_id' => true,
            'version' => '7',
        ]);
        $this->service->expects(self::never())->method('execute');
        $this->authSession->expects(self::never())->method('getUser');
        $this->messageManager->expects(self::once())->method('addErrorMessage');
        $this->logger->expects(self::never())->method('critical');
        $this->redirect->expects(self::once())
            ->method('setPath')
            ->with('*/*/index')
            ->willReturnSelf();

        self::assertSame($this->redirect, $this->subject->execute());
    }

    public function testRejectsInvalidAdminActorWithoutCallingService(): void
    {
        $this->setRequestParams([
            'entity_id' => '41',
            'version' => '7',
        ]);
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(0);
        $this->authSession->method('getUser')->willReturn($user);
        $this->service->expects(self::never())->method('execute');
        $this->messageManager->expects(self::once())->method('addErrorMessage');
        $this->logger->expects(self::never())->method('critical');
        $this->redirect->expects(self::once())
            ->method('setPath')
            ->with('*/*/view', ['entity_id' => 41])
            ->willReturnSelf();

        self::assertSame($this->redirect, $this->subject->execute());
    }

    public function testRejectsCommentLongerThanOneThousandCharacters(): void
    {
        $this->setRequestParams([
            'entity_id' => '41',
            'version' => '7',
            'comment' => str_repeat('a', 1001),
        ]);
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(9);
        $this->authSession->method('getUser')->willReturn($user);
        $this->service->expects(self::never())->method('execute');
        $this->messageManager->expects(self::once())->method('addErrorMessage');
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
                'Exchange replacement order creation failed.',
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
                    !str_contains((string)$message, 'sensitive infrastructure detail')
            ));
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
