<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Ui\Component\Listing\Button;

use Bonlineco\SalesExchange\Api\ConfigInterface;
use Bonlineco\SalesExchange\Model\Workflow\AdminActionMap;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Provide the exchange-grid creation action only when it can be used.
 */
class CreateExchange implements ButtonProviderInterface
{
    private ConfigInterface $config;

    private AuthorizationInterface $authorization;

    private UrlInterface $urlBuilder;

    private Json $json;

    private StoreManagerInterface $storeManager;

    public function __construct(
        ConfigInterface $config,
        AuthorizationInterface $authorization,
        UrlInterface $urlBuilder,
        Json $json,
        StoreManagerInterface $storeManager
    ) {
        $this->config = $config;
        $this->authorization = $authorization;
        $this->urlBuilder = $urlBuilder;
        $this->json = $json;
        $this->storeManager = $storeManager;
    }

    /**
     * @return array<string, mixed>
     */
    public function getButtonData(): array
    {
        if (!$this->isEnabledForAnyStore()
            || !$this->authorization->isAllowed(AdminActionMap::ACL_CREATE)
            || !$this->authorization->isAllowed(AdminActionMap::ACL_SALES_ORDER_VIEW)
        ) {
            return [];
        }

        return [
            'label' => __('Create Exchange'),
            'class' => 'primary',
            'on_click' => sprintf(
                'location.href = %s;',
                $this->json->serialize(
                    $this->urlBuilder->getUrl('salesexchange/exchange/new')
                )
            ),
            'sort_order' => 10,
            'aclResource' => AdminActionMap::ACL_CREATE,
        ];
    }

    private function isEnabledForAnyStore(): bool
    {
        $stores = $this->storeManager->getStores();
        if ($stores === []) {
            return $this->config->isEnabled();
        }

        foreach ($stores as $store) {
            if ((bool)$store->getIsActive()
                && $this->config->isEnabled((int)$store->getId())
            ) {
                return true;
            }
        }

        return false;
    }
}
