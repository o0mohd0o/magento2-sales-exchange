<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\Payment;

use Bonlineco\SalesExchange\Model\ReplacementOrder\ExecutionContext;
use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Payment\Helper\Data as PaymentData;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Payment\Model\Method\Logger;
use Magento\Quote\Api\Data\CartInterface;

/**
 * Hidden offline method for a trusted exchange replacement quote only.
 */
class Replacement extends AbstractMethod
{
    public const CODE = 'bonlineco_sales_exchange';

    /**
     * @var string
     */
    protected $_code = self::CODE;

    /**
     * @var bool
     */
    protected $_isOffline = true;

    /**
     * Trusted replacement-order execution state.
     *
     * @var ExecutionContext
     */
    private ExecutionContext $executionContext;

    /**
     * Initialize the hidden replacement payment method.
     *
     * @param Context $context
     * @param Registry $registry
     * @param ExtensionAttributesFactory $extensionFactory
     * @param AttributeValueFactory $customAttributeFactory
     * @param PaymentData $paymentData
     * @param ScopeConfigInterface $scopeConfig
     * @param Logger $logger
     * @param ExecutionContext $executionContext
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array $data
     * @param DirectoryHelper|null $directory
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ExtensionAttributesFactory $extensionFactory,
        AttributeValueFactory $customAttributeFactory,
        PaymentData $paymentData,
        ScopeConfigInterface $scopeConfig,
        Logger $logger,
        ExecutionContext $executionContext,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = [],
        ?DirectoryHelper $directory = null
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data,
            $directory
        );
        $this->executionContext = $executionContext;
    }

    /**
     * Available only to the exact quote marked by the active in-process intent.
     *
     * @param CartInterface|null $quote
     * @return bool
     */
    public function isAvailable(?CartInterface $quote = null)
    {
        return $this->executionContext->isTrustedQuote($quote)
            && parent::isAvailable($quote);
    }
}
