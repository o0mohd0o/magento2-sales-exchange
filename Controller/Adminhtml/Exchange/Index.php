<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Controller\Adminhtml\Exchange;

use Bonlineco\SalesExchange\Model\Authorization\ExchangeReadAuthorization;
use Bonlineco\SalesExchange\Model\Workflow\AdminActionMap;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

/**
 * Exchange order listing.
 */
class Index extends Action
{
    public const ADMIN_RESOURCE = AdminActionMap::ACL_VIEW;

    private PageFactory $pageFactory;

    private ExchangeReadAuthorization $readAuthorization;

    public function __construct(
        Context $context,
        PageFactory $pageFactory,
        ExchangeReadAuthorization $readAuthorization
    ) {
        parent::__construct($context);
        $this->pageFactory = $pageFactory;
        $this->readAuthorization = $readAuthorization;
    }

    public function execute(): Page
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Bonlineco_SalesExchange::exchange_orders');
        $page->getConfig()->getTitle()->prepend(__('Exchange Orders'));

        return $page;
    }

    protected function _isAllowed(): bool
    {
        return $this->readAuthorization->isAllowed();
    }
}
