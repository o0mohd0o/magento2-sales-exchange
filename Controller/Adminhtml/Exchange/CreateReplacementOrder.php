<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Controller\Adminhtml\Exchange;

use Bonlineco\SalesExchange\Api\CreateReplacementOrderInterface;
use Bonlineco\SalesExchange\Model\Authorization\ReplacementOrderAuthorization;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\StringUtils;
use Psr\Log\LoggerInterface;

/**
 * Create or recover the native Magento order for a frozen replacement.
 */
class CreateReplacementOrder extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = ReplacementOrderAuthorization::ACL_REPLACEMENT_ORDER;

    private const MAX_COMMENT_LENGTH = 1000;

    private CreateReplacementOrderInterface $createReplacementOrder;

    private Session $authSession;

    private ReplacementOrderAuthorization $replacementOrderAuthorization;

    private StringUtils $stringUtils;

    private LoggerInterface $logger;

    public function __construct(
        Context $context,
        CreateReplacementOrderInterface $createReplacementOrder,
        Session $authSession,
        ReplacementOrderAuthorization $replacementOrderAuthorization,
        StringUtils $stringUtils,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->createReplacementOrder = $createReplacementOrder;
        $this->authSession = $authSession;
        $this->replacementOrderAuthorization = $replacementOrderAuthorization;
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
            $documentLink = $this->createReplacementOrder->execute(
                $exchangeId,
                $expectedVersion,
                (int)$user->getId(),
                $this->getComment()
            );
            $reference = $documentLink->getIncrementId()
                ?: (string)$documentLink->getDocumentId();
            $this->messageManager->addSuccessMessage(
                __('Replacement order #%1 is reconciled with this exchange.', $reference)
            );
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
        } catch (\Throwable $exception) {
            $this->logger->critical(
                'Exchange replacement order creation failed.',
                [
                    'exception' => $exception,
                    'exchange_id' => $exchangeId,
                ]
            );
            $this->messageManager->addErrorMessage(
                __('The replacement order could not be created. Check the logs and try again.')
            );
        }

        if ($exchangeId <= 0) {
            return $redirect->setPath('*/*/index');
        }

        return $redirect->setPath('*/*/view', ['entity_id' => $exchangeId]);
    }

    protected function _isAllowed(): bool
    {
        return $this->replacementOrderAuthorization->isAllowed();
    }

    /**
     * @throws LocalizedException
     */
    private function getPositiveIntegerParam(string $name): int
    {
        $value = $this->getRequest()->getParam($name);
        if (!is_int($value) && !is_string($value)) {
            throw new LocalizedException(__('The exchange request is invalid.'));
        }
        $value = trim((string)$value);
        if (!preg_match('/^[1-9][0-9]*$/D', $value)) {
            throw new LocalizedException(__('The exchange request is invalid.'));
        }
        $validated = filter_var(
            $value,
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
            throw new LocalizedException(__('The replacement order comment is invalid.'));
        }
        $comment = trim((string)$value);
        if ($comment === '') {
            return null;
        }
        if ($this->stringUtils->strlen($comment) > self::MAX_COMMENT_LENGTH) {
            throw new LocalizedException(
                __('The replacement order comment cannot exceed %1 characters.', self::MAX_COMMENT_LENGTH)
            );
        }

        return $comment;
    }
}
