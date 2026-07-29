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
use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderAddressRepositoryInterface;

/**
 * Verify final native order addresses against the original sales snapshot.
 */
class NativeOrderAddressValidator
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

    /**
     * @return array{
     *     billing: array<string, mixed>,
     *     shipping: array<string, mixed>
     * }
     */
    public function snapshot(
        OrderInterface $originalOrder,
        OrderInterface $replacementOrder
    ): array {
        $source = $this->resolve($originalOrder);
        $target = $this->resolve($replacementOrder);
        $fallbackEmail = (string)$originalOrder->getCustomerEmail();

        return [
            'billing' => $this->compare(
                $source['billing'],
                $target['billing'],
                $fallbackEmail
            ),
            'shipping' => $this->compare(
                $source['shipping'],
                $target['shipping'],
                $fallbackEmail
            ),
        ];
    }

    /**
     * @return array{billing: OrderAddressInterface, shipping: OrderAddressInterface}
     */
    private function resolve(OrderInterface $order): array
    {
        $orderId = (int)$order->getEntityId();
        if ($orderId <= 0) {
            throw new InvariantViolationException(
                __('A native order address snapshot cannot be resolved.')
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
                    __('A native order address implementation is not supported.')
                );
            }
            $type = (string)$address->getAddressType();
            if (!in_array($type, ['billing', 'shipping'], true)) {
                continue;
            }
            if (isset($byType[$type])) {
                throw new InvariantViolationException(
                    __('A native order has duplicate "%1" addresses.', $type)
                );
            }
            $byType[$type] = $address;
        }
        if (!isset($byType['billing'], $byType['shipping'])) {
            throw new InvariantViolationException(
                __('Physical replacement orders require billing and shipping addresses.')
            );
        }

        return $byType;
    }

    /**
     * @return array<string, mixed>
     */
    private function compare(
        OrderAddressInterface $source,
        OrderAddressInterface $target,
        string $fallbackEmail
    ): array {
        $sourceRegionId = $this->nullableInt($source->getRegionId());
        $targetRegionId = $this->nullableInt($target->getRegionId());
        $expected = [
            'prefix' => $this->nullableString($source->getPrefix()),
            'firstname' => (string)$source->getFirstname(),
            'middlename' => $this->nullableString($source->getMiddlename()),
            'lastname' => (string)$source->getLastname(),
            'suffix' => $this->nullableString($source->getSuffix()),
            'company' => $this->nullableString($source->getCompany()),
            'street' => array_values($source->getStreet() ?? []),
            'city' => (string)$source->getCity(),
            'region' => $this->nullableString($source->getRegion()),
            'region_id' => $sourceRegionId,
            'region_code' => $this->nullableString($source->getRegionCode()),
            'postcode' => (string)$source->getPostcode(),
            'country_id' => (string)$source->getCountryId(),
            'telephone' => (string)$source->getTelephone(),
            'fax' => $this->nullableString($source->getFax()),
            'vat_id' => $this->nullableString($source->getVatId()),
            'email' => $fallbackEmail,
            'customer_id' => $this->nullableInt($source->getCustomerId()),
            'customer_address_id' => null,
        ];
        $actual = [
            'prefix' => $this->nullableString($target->getPrefix()),
            'firstname' => (string)$target->getFirstname(),
            'middlename' => $this->nullableString($target->getMiddlename()),
            'lastname' => (string)$target->getLastname(),
            'suffix' => $this->nullableString($target->getSuffix()),
            'company' => $this->nullableString($target->getCompany()),
            'street' => array_values($target->getStreet() ?? []),
            'city' => (string)$target->getCity(),
            'region' => $this->nullableString($target->getRegion()),
            'region_id' => $targetRegionId,
            'region_code' => $this->nullableString($target->getRegionCode()),
            'postcode' => (string)$target->getPostcode(),
            'country_id' => (string)$target->getCountryId(),
            'telephone' => (string)$target->getTelephone(),
            'fax' => $this->nullableString($target->getFax()),
            'vat_id' => $this->nullableString($target->getVatId()),
            'email' => (string)$target->getEmail(),
            'customer_id' => $this->nullableInt($target->getCustomerId()),
            'customer_address_id' => $this->nullableInt(
                $target->getCustomerAddressId()
            ),
        ];
        $expectedForComparison = $expected;
        $actualForComparison = $actual;
        // Keep legacy fingerprint fields, but do not treat Magento-derived
        // region codes or unmapped address-level customer IDs as snapshots.
        unset(
            $expectedForComparison['region_code'],
            $actualForComparison['region_code'],
            $expectedForComparison['customer_id'],
            $actualForComparison['customer_id']
        );
        if ($sourceRegionId !== null || $targetRegionId !== null) {
            unset(
                $expectedForComparison['region'],
                $actualForComparison['region']
            );
        }
        if ($actualForComparison !== $expectedForComparison) {
            throw new InvariantViolationException(
                __('A final replacement order address drifted from the original order snapshot.')
            );
        }

        return $actual;
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
