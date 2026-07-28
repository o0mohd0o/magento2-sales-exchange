<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Controller\Adminhtml\Exchange;

use Bonlineco\SalesExchange\Api\CreateCreditmemoInterface;
use Bonlineco\SalesExchange\Model\Authorization\ExchangeFinancialAuthorization;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\StringUtils;
use Psr\Log\LoggerInterface;

/**
 * Create the next server-owned offline credit memo for an accepted return.
 */
class CreateCreditmemo extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = ExchangeFinancialAuthorization::ACL_FINANCIAL;

    private const MAX_COMMENT_LENGTH = 1000;

    private CreateCreditmemoInterface $createCreditmemo;

    private Session $authSession;

    private ExchangeFinancialAuthorization $financialAuthorization;

    private StringUtils $stringUtils;

    private LoggerInterface $logger;

    public function __construct(
        Context $context,
        CreateCreditmemoInterface $createCreditmemo,
        Session $authSession,
        ExchangeFinancialAuthorization $financialAuthorization,
        StringUtils $stringUtils,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->createCreditmemo = $createCreditmemo;
        $this->authSession = $authSession;
        $this->financialAuthorization = $financialAuthorization;
        $this->stringUtils = $stringUtils;
        $this->logger = $logger;
    }

    public function execute(): Redirect
    {
        $exchangeId = 0;
        $redirect = $this->resultRedirectFactory->create();
        try {
            $exchangeId = $this->getPositiveIntegerParam('entity_id');
            $expectedVersion = $this->getPositiveIntegerParam('version');
            $user = $this->authSession->getUser();
            if ($user === null || (int)$user->getId() <= 0) {
                throw new LocalizedException(__('Your admin session is no longer valid.'));
            }
            $documentLink = $this->createCreditmemo->execute(
                $exchangeId,
                $expectedVersion,
                (int)$user->getId(),
                $this->getComment()
            );
            $reference = $documentLink->getIncrementId()
                ?: (string)$documentLink->getDocumentId();
            $this->messageManager->addSuccessMessage(
                __('Offline credit memo #%1 is reconciled with this exchange.', $reference)
            );
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
        } catch (\Throwable $exception) {
            $this->logger->critical(
                'Offline exchange credit memo creation failed.',
                [
                    'exception' => $exception,
                    'exchange_id' => $exchangeId,
                ]
            );
            $this->messageManager->addErrorMessage(
                __('The offline credit memo could not be created. Check the logs and try again.')
            );
        }

        if ($exchangeId <= 0) {
            return $redirect->setPath('*/*/index');
        }

        return $redirect->setPath('*/*/view', ['entity_id' => $exchangeId]);
    }

    protected function _isAllowed(): bool
    {
        return $this->financialAuthorization->isAllowed();
    }

    /**
     * @throws LocalizedException
     */
    private function getPositiveIntegerParam(string $name): int
    {
        $value = $this->getRequest()->getParam($name);
        if (!is_scalar($value)) {
            throw new LocalizedException(__('The exchange request is invalid.'));
        }
        $validated = filter_var(
            trim((string)$value),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if ($validated === false) {
            throw new LocalizedException(__('The exchange request is invalid.'));
        }

        return $validated;
    }

    /**
     * @throws LocalizedException
     */
    private function getComment(): ?string
    {
        $value = $this->getRequest()->getParam('comment');
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_scalar($value)) {
            throw new LocalizedException(__('The credit memo comment is invalid.'));
        }
        $comment = trim((string)$value);
        if ($comment === '') {
            return null;
        }
        if ($this->stringUtils->strlen($comment) > self::MAX_COMMENT_LENGTH) {
            throw new LocalizedException(
                __('The credit memo comment cannot exceed %1 characters.', self::MAX_COMMENT_LENGTH)
            );
        }

        return $comment;
    }
}
