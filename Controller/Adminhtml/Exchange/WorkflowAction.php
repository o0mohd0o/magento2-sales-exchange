<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Controller\Adminhtml\Exchange;

use Bonlineco\SalesExchange\Model\Workflow\AdminWorkflow;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * Shared thin adapter for explicit route-specific workflow controllers.
 */
abstract class WorkflowAction extends Action
{
    protected const ACTION = '';

    private AdminWorkflow $adminWorkflow;

    private Session $authSession;

    private LoggerInterface $logger;

    public function __construct(
        Context $context,
        AdminWorkflow $adminWorkflow,
        Session $authSession,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->adminWorkflow = $adminWorkflow;
        $this->authSession = $authSession;
        $this->logger = $logger;
    }

    public function execute(): Redirect
    {
        $exchangeId = (int)$this->getRequest()->getParam('entity_id');
        $redirect = $this->resultRedirectFactory->create();
        try {
            $user = $this->authSession->getUser();
            if ($user === null || (int)$user->getId() <= 0) {
                throw new LocalizedException(__('Your admin session is no longer valid.'));
            }
            $post = $this->getRequest()->getPostValue();
            $payload = is_array($post) ? $post : [];
            $this->adminWorkflow->execute(
                static::ACTION,
                $exchangeId,
                (int)$this->getRequest()->getParam('version'),
                (int)$user->getId(),
                $payload
            );
            $this->messageManager->addSuccessMessage(__('The exchange action was completed.'));
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
        } catch (\Throwable $exception) {
            $this->logger->critical($exception);
            $this->messageManager->addErrorMessage(
                __('The exchange action failed. Check the logs and try again.')
            );
        }

        return $redirect->setPath('*/*/view', ['entity_id' => $exchangeId]);
    }
}
