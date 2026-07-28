<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Block\Adminhtml\Order;

use Bonlineco\SalesExchange\Api\ConfigInterface;
use Bonlineco\SalesExchange\Api\ExchangeEligibilityInterface;
use Bonlineco\SalesExchange\Model\Workflow\AdminActionMap;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Container;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Element\AbstractBlock;

/**
 * Add the eligible-order toolbar action through a layout child block.
 */
class CreateButton extends AbstractBlock
{
    private ExchangeEligibilityInterface $exchangeEligibility;

    private ConfigInterface $config;

    public function __construct(
        Context $context,
        ExchangeEligibilityInterface $exchangeEligibility,
        ConfigInterface $config,
        array $data = []
    ) {
        $this->exchangeEligibility = $exchangeEligibility;
        $this->config = $config;
        parent::__construct($context, $data);
    }

    /**
     * @return $this
     */
    protected function _prepareLayout()
    {
        $orderId = (int)$this->getRequest()->getParam('order_id');
        $parent = $this->getParentBlock();
        if (!$parent instanceof Container
            || $orderId <= 0
            || !$this->_authorization->isAllowed(AdminActionMap::ACL_CREATE)
            || !$this->_authorization->isAllowed(AdminActionMap::ACL_SALES_ORDER_VIEW)
        ) {
            return parent::_prepareLayout();
        }
        try {
            $order = $this->exchangeEligibility->execute($orderId);
            $storeId = $order->getStoreId() === null ? null : (int)$order->getStoreId();
            if (!$this->config->isEnabled($storeId)) {
                return parent::_prepareLayout();
            }
            $parent->addButton(
                'bonlineco_create_exchange',
                [
                    'label' => __('Create Exchange'),
                    'class' => 'exchange primary',
                    'data_attribute' => [
                        'mage-init' => [
                            'Bonlineco_SalesExchange/js/action-post' => [
                                'url' => $this->getUrl(
                                    'salesexchange/exchange/new',
                                    ['order_id' => $orderId]
                                ),
                                'post' => false,
                            ],
                        ],
                    ],
                ]
            );
        } catch (LocalizedException $exception) {
            return parent::_prepareLayout();
        }

        return parent::_prepareLayout();
    }
}
