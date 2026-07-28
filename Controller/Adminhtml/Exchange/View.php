<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Controller\Adminhtml\Exchange;

use Bonlineco\SalesExchange\Api\ExchangeRepositoryInterface;
use Bonlineco\SalesExchange\Model\Authorization\ExchangeReadAuthorization;
use Bonlineco\SalesExchange\Model\Workflow\AdminActionMap;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\PageFactory;

/**
 * Exchange case detail page.
 */
class View extends Action
{
    public const ADMIN_RESOURCE = AdminActionMap::ACL_VIEW;

    private PageFactory $pageFactory;

    private ExchangeRepositoryInterface $exchangeRepository;

    private Registry $registry;

    private ExchangeReadAuthorization $readAuthorization;

    public function __construct(
        Context $context,
        PageFactory $pageFactory,
        ExchangeRepositoryInterface $exchangeRepository,
        Registry $registry,
        ExchangeReadAuthorization $readAuthorization
    ) {
        parent::__construct($context);
        $this->pageFactory = $pageFactory;
        $this->exchangeRepository = $exchangeRepository;
        $this->registry = $registry;
        $this->readAuthorization = $readAuthorization;
    }

    public function execute(): ResultInterface
    {
        try {
            $exchange = $this->exchangeRepository->getById(
                (int)$this->getRequest()->getParam('entity_id')
            );
            $this->registry->register('bonlineco_sales_exchange', $exchange);
            $page = $this->pageFactory->create();
            $page->setActiveMenu('Bonlineco_SalesExchange::exchange_orders');
            $page->getConfig()->getTitle()->prepend(
                __('Exchange %1', $exchange->getIncrementId())
            );

            return $page;
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
            /** @var Redirect $redirect */
            $redirect = $this->resultRedirectFactory->create();

            return $redirect->setPath('*/*/index');
        }
    }

    protected function _isAllowed(): bool
    {
        return $this->readAuthorization->isAllowed();
    }
}
