<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Integration\Model;

use Bonlineco\SalesExchange\Model\ReplacementOrder\AddressSnapshotCopier;
use Bonlineco\SalesExchange\Model\ReplacementOrder\NativeOrderAddressValidator;
use Magento\Backend\Model\Session\Quote as BackendQuoteSession;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\ToOrderAddress;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\ResourceModel\Quote as QuoteResource;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Api\Data\OrderAddressSearchResultInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderAddressRepositoryInterface;
use Magento\Store\Model\Store;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Exercise Magento's persisted address normalization with real quote models.
 *
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class AddressNormalizationTest extends TestCase
{
    private const HISTORICAL_REGION = 'Historical California Label';
    private const ORDER_EMAIL = 'sales-exchange-integration@example.com';

    public function testNormalizedQuoteAndOrderAddressesRemainValid(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $localeResolver = $objectManager->get(ResolverInterface::class);
        $originalLocale = $localeResolver->getLocale();
        $localeResolver->setLocale('en_US');

        try {
            $region = $objectManager->get(RegionFactory::class)
                ->create()
                ->loadByCode('CA', 'US');
            self::assertGreaterThan(0, (int)$region->getId());
            $sources = [
                $this->address(
                    'billing',
                    (int)$region->getId(),
                    'historical-address@example.com'
                ),
                $this->address(
                    'shipping',
                    (int)$region->getId(),
                    self::ORDER_EMAIL
                ),
            ];
            $copier = new AddressSnapshotCopier(
                $this->addressRepository([$sources, $sources]),
                $this->searchCriteriaBuilderFactory()
            );
            $order = $this->order(50);
            /** @var Quote $quote */
            $quote = $objectManager->get(QuoteFactory::class)->create();
            $quote->setStoreId(Store::DISTRO_STORE_ID)
                ->setCustomerIsGuest(true)
                ->setCustomerEmail(self::ORDER_EMAIL)
                ->setIsActive(false);
            $copier->execute($order, $quote);
            $quote->getShippingAddress()->setSameAsBilling(0);
            $quoteSession = $objectManager->get(BackendQuoteSession::class);
            $hadReordered = $quoteSession->hasData('reordered');
            $originalReordered = $quoteSession->getData('reordered');
            $quoteSession->setData('reordered', true);
            try {
                $objectManager->get(CartRepositoryInterface::class)
                    ->save($quote);
            } finally {
                if ($hadReordered) {
                    $quoteSession->setData('reordered', $originalReordered);
                } else {
                    $quoteSession->unsetData('reordered');
                }
            }

            /** @var Quote $freshQuote */
            $freshQuote = $objectManager->get(QuoteFactory::class)->create();
            $objectManager->get(QuoteResource::class)->load(
                $freshQuote,
                (int)$quote->getId()
            );
            $billing = $freshQuote->getBillingAddress();
            $shipping = $freshQuote->getShippingAddress();

            self::assertNotSame($billing, $shipping);
            self::assertNotNull($freshQuote->getOrigOrderId());
            self::assertSame(0, (int)$freshQuote->getOrigOrderId());
            self::assertSame(1, (int)$shipping->getSameAsBilling());
            self::assertSame(
                (int)$region->getId(),
                (int)$billing->getRegionId()
            );
            self::assertSame('California', (string)$billing->getRegion());
            self::assertNotSame(
                self::HISTORICAL_REGION,
                (string)$billing->getRegion()
            );
            $copier->assertMatches($order, $freshQuote);

            $converter = $objectManager->get(ToOrderAddress::class);
            $convertedBilling = $converter->convert(
                $billing,
                [
                    'address_type' => 'billing',
                    'email' => self::ORDER_EMAIL,
                ]
            );
            $convertedShipping = $converter->convert(
                $shipping,
                [
                    'address_type' => 'shipping',
                    'email' => self::ORDER_EMAIL,
                ]
            );
            self::assertNull($convertedBilling->getCustomerId());
            self::assertNull($convertedBilling->getCustomerAddressId());
            self::assertSame(
                self::ORDER_EMAIL,
                (string)$convertedBilling->getEmail()
            );

            $validator = new NativeOrderAddressValidator(
                $this->addressRepository([
                    $sources,
                    [$convertedBilling, $convertedShipping],
                ]),
                $this->searchCriteriaBuilderFactory()
            );
            $snapshot = $validator->snapshot(
                $this->order(50),
                $this->order(60)
            );
            self::assertSame(
                (int)$region->getId(),
                $snapshot['billing']['region_id']
            );
            self::assertSame(
                'California',
                $snapshot['billing']['region']
            );
            self::assertSame(
                self::ORDER_EMAIL,
                $snapshot['billing']['email']
            );
        } finally {
            $localeResolver->setLocale($originalLocale);
        }
    }

    /**
     * @param array<int, OrderAddressInterface[]> $addressSets
     */
    private function addressRepository(
        array $addressSets
    ): OrderAddressRepositoryInterface {
        $results = [];
        foreach ($addressSets as $addresses) {
            $result = $this->createMock(
                OrderAddressSearchResultInterface::class
            );
            $result->method('getItems')->willReturn($addresses);
            $results[] = $result;
        }
        $repository = $this->createMock(
            OrderAddressRepositoryInterface::class
        );
        $repository->expects(self::exactly(count($results)))
            ->method('getList')
            ->willReturnOnConsecutiveCalls(...$results);

        return $repository;
    }

    private function searchCriteriaBuilderFactory(): SearchCriteriaBuilderFactory
    {
        $criteria = $this->createMock(SearchCriteriaInterface::class);
        $builder = $this->createMock(SearchCriteriaBuilder::class);
        $builder->method('addFilter')->willReturnSelf();
        $builder->method('setPageSize')->willReturnSelf();
        $builder->method('create')->willReturn($criteria);
        $factory = $this->createMock(SearchCriteriaBuilderFactory::class);
        $factory->method('create')->willReturn($builder);

        return $factory;
    }

    private function order(int $entityId): OrderInterface
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn($entityId);
        $order->method('getCustomerId')->willReturn(null);
        $order->method('getCustomerEmail')->willReturn(self::ORDER_EMAIL);

        return $order;
    }

    private function address(
        string $type,
        int $regionId,
        string $email
    ): OrderAddressInterface {
        $address = $this->createMock(OrderAddressInterface::class);
        $address->method('getAddressType')->willReturn($type);
        $address->method('getPrefix')->willReturn(null);
        $address->method('getFirstname')->willReturn('Integration');
        $address->method('getMiddlename')->willReturn(null);
        $address->method('getLastname')->willReturn('Customer');
        $address->method('getSuffix')->willReturn(null);
        $address->method('getCompany')->willReturn(null);
        $address->method('getStreet')->willReturn(['1 Main Street']);
        $address->method('getCity')->willReturn('Los Angeles');
        $address->method('getRegion')->willReturn(self::HISTORICAL_REGION);
        $address->method('getRegionId')->willReturn($regionId);
        $address->method('getRegionCode')->willReturn('CA');
        $address->method('getPostcode')->willReturn('90001');
        $address->method('getCountryId')->willReturn('US');
        $address->method('getTelephone')->willReturn('5555550100');
        $address->method('getFax')->willReturn(null);
        $address->method('getVatId')->willReturn(null);
        $address->method('getEmail')->willReturn($email);
        $address->method('getCustomerId')->willReturn(null);
        $address->method('getCustomerAddressId')->willReturn(null);

        return $address;
    }
}
