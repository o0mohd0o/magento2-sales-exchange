<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\Carrier;

use Bonlineco\SalesExchange\Model\ReplacementOrder\ExecutionContext;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Rate\Result;
use Magento\Shipping\Model\Rate\ResultFactory;
use Psr\Log\LoggerInterface;

/**
 * Zero-rate delivery method for a trusted exchange replacement quote only.
 */
class Replacement extends AbstractCarrier implements CarrierInterface
{
    public const CARRIER_CODE = 'bonlineco_sales_exchange';

    public const METHOD_CODE = 'replacement';

    /**
     * @var string
     */
    protected $_code = self::CARRIER_CODE;

    /**
     * @var bool
     */
    protected $_isFixed = true;

    /**
     * Native rate result factory.
     *
     * @var ResultFactory
     */
    private ResultFactory $resultFactory;

    /**
     * Native rate method factory.
     *
     * @var MethodFactory
     */
    private MethodFactory $methodFactory;

    /**
     * Trusted replacement-order execution state.
     *
     * @var ExecutionContext
     */
    private ExecutionContext $executionContext;

    /**
     * Initialize the trusted replacement carrier.
     *
     * @param ScopeConfigInterface $scopeConfig
     * @param \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory
     * @param LoggerInterface $logger
     * @param ResultFactory $resultFactory
     * @param MethodFactory $methodFactory
     * @param ExecutionContext $executionContext
     * @param array $data
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory,
        LoggerInterface $logger,
        ResultFactory $resultFactory,
        MethodFactory $methodFactory,
        ExecutionContext $executionContext,
        array $data = []
    ) {
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
        $this->resultFactory = $resultFactory;
        $this->methodFactory = $methodFactory;
        $this->executionContext = $executionContext;
    }

    /**
     * Collect the trusted zero-price replacement delivery rate.
     *
     * @param RateRequest $request
     * @return Result|false
     */
    public function collectRates(RateRequest $request)
    {
        $quote = $this->resolveQuote($request);
        if (!$this->getConfigFlag('active')
            || !$this->executionContext->isTrustedQuote($quote)
        ) {
            return false;
        }

        $result = $this->resultFactory->create();
        $method = $this->methodFactory->create();
        $method->setCarrier(self::CARRIER_CODE);
        $method->setCarrierTitle((string)$this->getConfigData('title'));
        $method->setMethod(self::METHOD_CODE);
        $method->setMethodTitle((string)$this->getConfigData('name'));
        $method->setPrice(0);
        $method->setCost(0);
        $result->append($method);

        return $result;
    }

    /**
     * Return the carrier's sole method.
     *
     * @return array<string, string>
     */
    public function getAllowedMethods(): array
    {
        return [self::METHOD_CODE => (string)$this->getConfigData('name')];
    }

    /**
     * Resolve one unambiguous quote from the native rate request items.
     *
     * @param RateRequest $request
     * @return CartInterface|null
     */
    private function resolveQuote(RateRequest $request): ?CartInterface
    {
        $resolved = null;
        $items = $request->getAllItems();
        if (!is_array($items) || $items === []) {
            return null;
        }

        foreach ($items as $item) {
            if (!is_object($item) || !is_callable([$item, 'getQuote'])) {
                return null;
            }
            $candidate = $item->getQuote();
            if (!$candidate instanceof CartInterface) {
                return null;
            }
            if ($resolved !== null && $resolved !== $candidate) {
                return null;
            }
            $resolved = $candidate;
        }

        return $resolved;
    }
}
