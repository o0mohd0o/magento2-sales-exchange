<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Controller\Adminhtml\Exchange;

use Bonlineco\SalesExchange\Api\ReconcileSettlementInterface;
use Bonlineco\SalesExchange\Model\Authorization\SettlementAuthorization;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\StringUtils;
use Psr\Log\LoggerInterface;

/**
 * Reconcile the server-owned native invoice and settlement ledger.
 */
class ReconcileSettlement extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = SettlementAuthorization::ACL_SETTLEMENT;

    private const MAX_EXTERNAL_REFERENCE_LENGTH = 255;
    private const MAX_COMMENT_LENGTH = 1000;

    private ReconcileSettlementInterface $reconcileSettlement;

    private Session $authSession;

    private SettlementAuthorization $settlementAuthorization;

    private StringUtils $stringUtils;

    private LoggerInterface $logger;

    public function __construct(
        Context $context,
        ReconcileSettlementInterface $reconcileSettlement,
        Session $authSession,
        SettlementAuthorization $settlementAuthorization,
        StringUtils $stringUtils,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->reconcileSettlement = $reconcileSettlement;
        $this->authSession = $authSession;
        $this->settlementAuthorization = $settlementAuthorization;
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
                throw new LocalizedException(
                    __('Your admin session is no longer valid.')
                );
            }
            $this->reconcileSettlement->execute(
                $exchangeId,
                $expectedVersion,
                (int)$user->getId(),
                $this->getExternalReference(),
                $this->getComment()
            );
            $this->messageManager->addSuccessMessage(
                __('The exchange settlement was reconciled successfully.')
            );
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
        } catch (\Throwable $exception) {
            $this->logger->critical(
                'Exchange settlement reconciliation failed.',
                [
                    'exception' => $exception,
                    'exchange_id' => $exchangeId,
                ]
            );
            $this->messageManager->addErrorMessage(
                __(
                    'The exchange settlement could not be reconciled. '
                    . 'Check the logs and try again.'
                )
            );
        }

        if ($exchangeId <= 0) {
            return $redirect->setPath('*/*/index');
        }

        return $redirect->setPath(
            '*/*/view',
            ['entity_id' => $exchangeId]
        );
    }

    protected function _isAllowed(): bool
    {
        return $this->settlementAuthorization->isAllowed();
    }

    /**
     * @throws LocalizedException
     */
    private function getPositiveIntegerParam(string $name): int
    {
        $value = $this->getRequest()->getParam($name);
        if (!is_int($value) && !is_string($value)) {
            throw new LocalizedException(
                __('The exchange request is invalid.')
            );
        }
        $value = trim((string)$value);
        if (!preg_match('/^[1-9][0-9]*$/D', $value)) {
            throw new LocalizedException(
                __('The exchange request is invalid.')
            );
        }
        $validated = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if ($validated === false) {
            throw new LocalizedException(
                __('The exchange request is invalid.')
            );
        }

        return $validated;
    }

    /**
     * @throws LocalizedException
     */
    private function getExternalReference(): ?string
    {
        $value = $this->getRequest()->getParam('external_reference');
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new LocalizedException(
                __('The external settlement reference is invalid.')
            );
        }
        $reference = trim($value);
        if ($reference === '') {
            return null;
        }
        if ($this->stringUtils->strlen($reference)
            > self::MAX_EXTERNAL_REFERENCE_LENGTH
        ) {
            throw new LocalizedException(
                __(
                    'The external settlement reference cannot exceed %1 characters.',
                    self::MAX_EXTERNAL_REFERENCE_LENGTH
                )
            );
        }

        return $reference;
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
        if (!is_string($value)) {
            throw new LocalizedException(
                __('The settlement comment is invalid.')
            );
        }
        $comment = trim($value);
        if ($comment === '') {
            return null;
        }
        if ($this->stringUtils->strlen($comment)
            > self::MAX_COMMENT_LENGTH
        ) {
            throw new LocalizedException(
                __(
                    'The settlement comment cannot exceed %1 characters.',
                    self::MAX_COMMENT_LENGTH
                )
            );
        }

        return $comment;
    }
}
