<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\ReplacementOrder;

use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address as QuoteAddress;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderAddressRepositoryInterface;

/**
 * Copy only core order-address snapshot fields into isolated quote addresses.
 */
class AddressSnapshotCopier
{
    private OrderAddressRepositoryInterface $addressRepository;

    private SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory;

    public function __construct(
        OrderAddressRepositoryInterface $addressRepository,
        SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory
    ) {
        $this->addressRepository = $addressRepository;
        $this->searchCriteriaBuilderFactory = $searchCriteriaBuilderFactory;
    }

    public function execute(OrderInterface $order, Quote $quote): void
    {
        $byType = $this->resolve($order);
        $customerId = $order->getCustomerId();
        $billingAddress = $quote->getBillingAddress();
        $shippingAddress = $quote->getShippingAddress();
        $this->assertDistinctAddresses($billingAddress, $shippingAddress);
        $this->copy(
            $byType['billing'],
            $billingAddress,
            $customerId === null ? null : (int)$customerId,
            (string)$order->getCustomerEmail()
        );
        $this->copy(
            $byType['shipping'],
            $shippingAddress,
            $customerId === null ? null : (int)$customerId,
            (string)$order->getCustomerEmail()
        );
        $shippingAddress->setSameAsBilling(false);
    }

    public function assertMatches(OrderInterface $order, Quote $quote): void
    {
        $byType = $this->resolve($order);
        $customerId = $order->getCustomerId();
        $customerId = $customerId === null ? null : (int)$customerId;
        $email = (string)$order->getCustomerEmail();
        $billingAddress = $quote->getBillingAddress();
        $shippingAddress = $quote->getShippingAddress();
        $this->assertDistinctAddresses($billingAddress, $shippingAddress);
        $this->assertAddressMatches(
            $byType['billing'],
            $billingAddress,
            $customerId,
            $email
        );
        $this->assertAddressMatches(
            $byType['shipping'],
            $shippingAddress,
            $customerId,
            $email
        );
    }

    private function assertDistinctAddresses(
        QuoteAddress $billingAddress,
        QuoteAddress $shippingAddress
    ): void {
        if ($billingAddress === $shippingAddress) {
            throw new InvariantViolationException(
                __('The replacement shipping snapshot cannot alias its billing address.')
            );
        }
    }

    private function copy(
        OrderAddressInterface $source,
        QuoteAddress $target,
        ?int $customerId,
        string $fallbackEmail
    ): void {
        $email = trim((string)$source->getEmail());
        $target->setPrefix($source->getPrefix())
            ->setFirstname($source->getFirstname())
            ->setMiddlename($source->getMiddlename())
            ->setLastname($source->getLastname())
            ->setSuffix($source->getSuffix())
            ->setCompany($source->getCompany())
            ->setStreet($source->getStreet() ?? [])
            ->setCity($source->getCity())
            ->setRegion($source->getRegion())
            ->setRegionId($source->getRegionId())
            ->setRegionCode($source->getRegionCode())
            ->setPostcode($source->getPostcode())
            ->setCountryId($source->getCountryId())
            ->setTelephone($source->getTelephone())
            ->setFax($source->getFax())
            ->setVatId($source->getVatId())
            ->setEmail($email === '' ? $fallbackEmail : $email)
            ->setCustomerId($customerId)
            ->setCustomerAddressId(null)
            ->setSaveInAddressBook(false);
    }

    /**
     * @return array{billing: OrderAddressInterface, shipping: OrderAddressInterface}
     */
    private function resolve(OrderInterface $order): array
    {
        $orderId = (int)$order->getEntityId();
        if ($orderId <= 0) {
            throw new InvariantViolationException(
                __('The original order address snapshot cannot be resolved.')
            );
        }
        /** @var SearchCriteriaBuilder $builder */
        $builder = $this->searchCriteriaBuilderFactory->create();
        $addresses = $this->addressRepository->getList(
            $builder->addFilter(OrderAddressInterface::PARENT_ID, $orderId)
                ->setPageSize(3)
                ->create()
        )->getItems();
        $byType = [];
        foreach ($addresses as $address) {
            if (!$address instanceof OrderAddressInterface) {
                throw new InvariantViolationException(
                    __('The original order address implementation is not supported.')
                );
            }
            $type = (string)$address->getAddressType();
            if (!in_array($type, ['billing', 'shipping'], true)) {
                continue;
            }
            if (isset($byType[$type])) {
                throw new InvariantViolationException(
                    __('The original order has duplicate "%1" address snapshots.', $type)
                );
            }
            $byType[$type] = $address;
        }
        if (!isset($byType['billing'], $byType['shipping'])) {
            throw new InvariantViolationException(
                __('Physical replacement orders require original billing and shipping snapshots.')
            );
        }

        return $byType;
    }

    private function assertAddressMatches(
        OrderAddressInterface $source,
        QuoteAddress $target,
        ?int $customerId,
        string $fallbackEmail
    ): void {
        $sourceEmail = trim((string)$source->getEmail());
        $expectedEmail = $sourceEmail === '' ? $fallbackEmail : $sourceEmail;
        $targetCustomerId = $target->getCustomerId();
        $targetCustomerId = $targetCustomerId === null
            ? null
            : (int)$targetCustomerId;
        $matches = $this->nullableString($source->getPrefix())
                === $this->nullableString($target->getPrefix())
            && (string)$source->getFirstname() === (string)$target->getFirstname()
            && $this->nullableString($source->getMiddlename())
                === $this->nullableString($target->getMiddlename())
            && (string)$source->getLastname() === (string)$target->getLastname()
            && $this->nullableString($source->getSuffix())
                === $this->nullableString($target->getSuffix())
            && $this->nullableString($source->getCompany())
                === $this->nullableString($target->getCompany())
            && array_values($source->getStreet() ?? [])
                === array_values($target->getStreet())
            && (string)$source->getCity() === (string)$target->getCity()
            && $this->regionMatches($source, $target)
            && (string)$source->getPostcode() === (string)$target->getPostcode()
            && (string)$source->getCountryId() === (string)$target->getCountryId()
            && (string)$source->getTelephone() === (string)$target->getTelephone()
            && $this->nullableString($source->getFax())
                === $this->nullableString($target->getFax())
            && $this->nullableString($source->getVatId())
                === $this->nullableString($target->getVatId())
            && $expectedEmail === (string)$target->getEmail()
            && $targetCustomerId === $customerId
            && !$target->getCustomerAddressId()
            && !(bool)$target->getSaveInAddressBook();
        if (!$matches) {
            throw new InvariantViolationException(
                __('A prepared quote address drifted from the original order snapshot.')
            );
        }
    }

    private function regionMatches(
        OrderAddressInterface $source,
        QuoteAddress $target
    ): bool {
        $sourceRegionId = $this->nullableInt($source->getRegionId());
        $targetRegionId = $this->nullableInt($target->getRegionId());
        if ($sourceRegionId !== null || $targetRegionId !== null) {
            return $sourceRegionId === $targetRegionId;
        }

        return $this->nullableString($source->getRegion())
            === $this->nullableString($target->getRegion());
    }

    /**
     * @param mixed $value
     */
    private function nullableString($value): ?string
    {
        return $value === null ? null : (string)$value;
    }

    /**
     * @param mixed $value
     */
    private function nullableInt($value): ?int
    {
        return $value === null ? null : (int)$value;
    }
}
