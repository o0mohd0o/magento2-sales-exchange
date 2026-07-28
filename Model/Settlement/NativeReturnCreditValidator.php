<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Model\Settlement;

use Bonlineco\SalesExchange\Api\Data\DocumentLinkInterface;
use Bonlineco\SalesExchange\Api\Data\ExchangeInterface;
use Bonlineco\SalesExchange\Api\Data\ReturnItemInterface;
use Bonlineco\SalesExchange\Api\DocumentType;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\Creditmemo\DocumentValidator;
use Bonlineco\SalesExchange\Model\Creditmemo\Plan as CreditmemoPlan;
use Bonlineco\SalesExchange\Model\Creditmemo\ReturnCreditProjection;
use Bonlineco\SalesExchange\Model\Math\DecimalMath;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order\Creditmemo as SalesCreditmemo;

/**
 * Revalidate every linked native return credit before cash settlement.
 */
class NativeReturnCreditValidator
{
    private CreditmemoRepositoryInterface $creditmemoRepository;

    private DocumentValidator $documentValidator;

    private ReturnCreditProjection $returnCreditProjection;

    private DecimalMath $moneyMath;

    private DecimalMath $quantityMath;

    private SerializerInterface $serializer;

    public function __construct(
        CreditmemoRepositoryInterface $creditmemoRepository,
        DocumentValidator $documentValidator,
        ReturnCreditProjection $returnCreditProjection,
        DecimalMath $moneyMath,
        DecimalMath $quantityMath,
        SerializerInterface $serializer
    ) {
        $this->creditmemoRepository = $creditmemoRepository;
        $this->documentValidator = $documentValidator;
        $this->returnCreditProjection = $returnCreditProjection;
        $this->moneyMath = $moneyMath;
        $this->quantityMath = $quantityMath;
        $this->serializer = $serializer;
    }

    /**
     * @param array<int, array<string, mixed>> $returnRows
     * @param array<int, array<string, mixed>> $documentRows
     */
    public function execute(
        ExchangeInterface $exchange,
        OrderInterface $originalOrder,
        array $returnRows,
        array $documentRows
    ): void {
        $this->returnCreditProjection->assertFullyCredited($returnRows);
        $expectedQuantities = $this->creditedQuantities($returnRows);
        $actualQuantities = [];
        $amount = '0.0000';
        $baseAmount = '0.0000';
        $creditmemoIds = [];

        foreach ($documentRows as $link) {
            if ((string)($link[DocumentLinkInterface::DOCUMENT_TYPE] ?? '')
                !== DocumentType::CREDITMEMO
            ) {
                continue;
            }
            if ((int)($link[DocumentLinkInterface::EXCHANGE_ID] ?? 0)
                !== (int)$exchange->getEntityId()
            ) {
                throw new InvariantViolationException(
                    __('A linked native return credit belongs to another exchange.')
                );
            }
            $creditmemoId = (int)($link[DocumentLinkInterface::DOCUMENT_ID] ?? 0);
            if ($creditmemoId <= 0 || isset($creditmemoIds[$creditmemoId])) {
                throw new InvariantViolationException(
                    __('A linked native return credit is invalid or duplicated.')
                );
            }
            $creditmemoIds[$creditmemoId] = true;
            $quantities = $this->decodeQuantities(
                (string)($link[DocumentLinkInterface::ITEM_QUANTITIES_JSON] ?? '')
            );
            $creditmemo = $this->creditmemoRepository->get($creditmemoId);
            if ((int)$creditmemo->getState() !== SalesCreditmemo::STATE_REFUNDED) {
                throw new InvariantViolationException(
                    __('Every linked native credit memo must remain refunded.')
                );
            }
            $plan = new CreditmemoPlan($quantities, [], []);
            $this->documentValidator->assertPersisted(
                $creditmemo,
                $originalOrder,
                $exchange->getCurrencyCode(),
                $exchange->getBaseCurrencyCode(),
                (string)$link[DocumentLinkInterface::EXPECTED_AMOUNT],
                $plan
            );
            $this->documentValidator->assertPersistentFingerprint(
                $creditmemo,
                (string)($link[DocumentLinkInterface::SNAPSHOT_HASH] ?? '')
            );
            $totals = $this->documentValidator->snapshot(
                $creditmemo,
                $exchange->getOriginalOrderId(),
                $exchange->getCurrencyCode(),
                $exchange->getBaseCurrencyCode()
            );
            if ((int)$creditmemo->getEntityId() !== $creditmemoId
                || (string)$creditmemo->getIncrementId()
                    !== (string)$link[DocumentLinkInterface::INCREMENT_ID]
                || (string)$creditmemo->getOrderCurrencyCode()
                    !== (string)$link[DocumentLinkInterface::CURRENCY_CODE]
                || (string)$creditmemo->getBaseCurrencyCode()
                    !== (string)$link[DocumentLinkInterface::BASE_CURRENCY_CODE]
                || (string)$creditmemo->getState()
                    !== (string)$link[DocumentLinkInterface::DOCUMENT_STATUS]
                || $this->moneyMath->compare(
                    $totals['amount'],
                    (string)$link[DocumentLinkInterface::AMOUNT]
                ) !== 0
                || $this->moneyMath->compare(
                    $totals['base_amount'],
                    (string)$link[DocumentLinkInterface::BASE_AMOUNT]
                ) !== 0
            ) {
                throw new InvariantViolationException(
                    __('A linked native credit memo no longer matches its audit snapshot.')
                );
            }
            $amount = $this->moneyMath->add($amount, $totals['amount']);
            $baseAmount = $this->moneyMath->add(
                $baseAmount,
                $totals['base_amount']
            );
            foreach ($quantities as $orderItemId => $quantity) {
                $actualQuantities[$orderItemId] = $this->quantityMath->add(
                    $actualQuantities[$orderItemId] ?? '0',
                    $quantity
                );
            }
        }
        ksort($actualQuantities, SORT_NUMERIC);
        if ($actualQuantities !== $expectedQuantities
            || $this->moneyMath->compare(
                $amount,
                $exchange->getNativeReturnCreditAmount()
            ) !== 0
            || $this->moneyMath->compare(
                $baseAmount,
                $exchange->getBaseNativeReturnCreditAmount()
            ) !== 0
        ) {
            throw new InvariantViolationException(
                __('The live native return credits do not match the exchange handoff.')
            );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, string>
     */
    private function creditedQuantities(array $rows): array
    {
        $quantities = [];
        foreach ($rows as $row) {
            $orderItemId = (int)($row[ReturnItemInterface::ORDER_ITEM_ID] ?? 0);
            $credited = $this->quantityMath->assertNonNegative(
                (string)($row[ReturnItemInterface::CREDITED_QTY] ?? '0'),
                'Credited return quantity'
            );
            if ($orderItemId <= 0
                || $this->quantityMath->compare($credited, '0') <= 0
            ) {
                continue;
            }
            $quantities[$orderItemId] = $this->quantityMath->add(
                $quantities[$orderItemId] ?? '0',
                $credited
            );
        }
        ksort($quantities, SORT_NUMERIC);

        return $quantities;
    }

    /**
     * @return array<int, string>
     */
    private function decodeQuantities(string $json): array
    {
        $decoded = $json === '' ? null : $this->serializer->unserialize($json);
        if (!is_array($decoded) || $decoded === []) {
            throw new InvariantViolationException(
                __('The linked native return quantity snapshot is invalid.')
            );
        }
        $quantities = [];
        foreach ($decoded as $orderItemId => $quantity) {
            if (!is_scalar($quantity) || (int)$orderItemId <= 0) {
                throw new InvariantViolationException(
                    __('The linked native return quantity snapshot is invalid.')
                );
            }
            $normalized = $this->quantityMath->assertNonNegative(
                (string)$quantity,
                'Linked native return quantity'
            );
            if ($this->quantityMath->compare($normalized, '0') <= 0) {
                throw new InvariantViolationException(
                    __('Linked native return quantities must be positive.')
                );
            }
            $quantities[(int)$orderItemId] = $normalized;
        }
        ksort($quantities, SORT_NUMERIC);

        return $quantities;
    }
}
