<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Controller\Adminhtml\Exchange;

use Bonlineco\SalesExchange\Api\CreateExchangeInterface;
use Bonlineco\SalesExchange\Model\CreateExchangeRequestFactory;
use Bonlineco\SalesExchange\Model\Creation\CreateFormData;
use Bonlineco\SalesExchange\Model\Creation\RawInputValidator;
use Bonlineco\SalesExchange\Model\ReplacementSelectionFactory;
use Bonlineco\SalesExchange\Model\ReturnSelectionFactory;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * Persist a canonical draft aggregate through the atomic creation service.
 */
class Save extends CreationAction implements HttpPostActionInterface
{
    private CreateExchangeInterface $createExchange;

    private CreateExchangeRequestFactory $requestFactory;

    private ReturnSelectionFactory $returnSelectionFactory;

    private ReplacementSelectionFactory $replacementSelectionFactory;

    private Session $authSession;

    private LoggerInterface $logger;

    private RawInputValidator $rawInputValidator;

    private DataPersistorInterface $dataPersistor;

    public function __construct(
        Context $context,
        CreateExchangeInterface $createExchange,
        CreateExchangeRequestFactory $requestFactory,
        ReturnSelectionFactory $returnSelectionFactory,
        ReplacementSelectionFactory $replacementSelectionFactory,
        Session $authSession,
        LoggerInterface $logger,
        RawInputValidator $rawInputValidator,
        DataPersistorInterface $dataPersistor
    ) {
        parent::__construct($context);
        $this->createExchange = $createExchange;
        $this->requestFactory = $requestFactory;
        $this->returnSelectionFactory = $returnSelectionFactory;
        $this->replacementSelectionFactory = $replacementSelectionFactory;
        $this->authSession = $authSession;
        $this->logger = $logger;
        $this->rawInputValidator = $rawInputValidator;
        $this->dataPersistor = $dataPersistor;
    }

    public function execute(): Redirect
    {
        $orderId = (int)$this->getRequest()->getParam('order_id');
        $redirect = $this->resultRedirectFactory->create();
        $persistableData = null;
        $persistorKey = CreateFormData::getKey($orderId);
        $this->dataPersistor->clear($persistorKey);
        try {
            $user = $this->authSession->getUser();
            if ($user === null || (int)$user->getId() <= 0) {
                throw new LocalizedException(__('Your admin session is no longer valid.'));
            }
            $returnRows = $this->getRequest()->getParam('return_items', []);
            $replacementRows = $this->getRequest()->getParam('replacement_items', []);
            $customerNote = $this->getRequest()->getParam('customer_note');
            $internalNote = $this->getRequest()->getParam('internal_note');
            $this->rawInputValidator->execute(
                $returnRows,
                $replacementRows,
                $customerNote,
                $internalNote
            );
            /** @var array<mixed> $returnRows */
            /** @var array<mixed> $replacementRows */
            $persistableData = $this->buildPersistableData(
                $orderId,
                $returnRows,
                $replacementRows,
                $customerNote,
                $internalNote
            );
            $request = $this->requestFactory->create();
            $request->setOrderId($orderId)
                ->setReturnItems($this->buildReturnSelections($returnRows))
                ->setReplacementItems($this->buildReplacementSelections($replacementRows))
                ->setCustomerNote($this->getScalarPostValue('customer_note'))
                ->setInternalNote($this->getScalarPostValue('internal_note'))
                ->setActorId((int)$user->getId());
            $exchange = $this->createExchange->execute($request);
            $this->messageManager->addSuccessMessage(
                __('Draft exchange %1 was created.', $exchange->getIncrementId())
            );
            $this->dataPersistor->clear($persistorKey);

            return $redirect->setPath('*/*/view', ['entity_id' => $exchange->getEntityId()]);
        } catch (LocalizedException $exception) {
            if ($persistableData !== null) {
                $this->dataPersistor->set($persistorKey, $persistableData);
            }
            $this->messageManager->addErrorMessage($exception->getMessage());
        } catch (\Throwable $exception) {
            if ($persistableData !== null) {
                $this->dataPersistor->set($persistorKey, $persistableData);
            }
            $this->logger->critical($exception);
            $this->messageManager->addErrorMessage(
                __('The draft exchange could not be created. Check the logs and try again.')
            );
        }

        return $redirect->setPath('*/*/new', ['order_id' => $orderId]);
    }

    /**
     * @return \Bonlineco\SalesExchange\Api\Data\ReturnSelectionInterface[]
     */
    private function buildReturnSelections(array $rows): array
    {
        $selections = [];
        foreach ($rows as $key => $row) {
            if (!is_array($row) || (string)($row['selected'] ?? '0') !== '1') {
                continue;
            }
            $orderItemId = (int)($row['order_item_id'] ?? $key);
            $selection = $this->returnSelectionFactory->create();
            $selection->setOrderItemId($orderItemId)
                ->setQuantity($this->scalarToString($row['qty'] ?? null))
                ->setReasonCode($this->scalarToString($row['reason_code'] ?? null));
            $selections[] = $selection;
        }

        return $selections;
    }

    /**
     * @return \Bonlineco\SalesExchange\Api\Data\ReplacementSelectionInterface[]
     */
    private function buildReplacementSelections(array $rows): array
    {
        $selections = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sku = trim($this->scalarToString($row['sku'] ?? null));
            $quantity = trim($this->scalarToString($row['qty'] ?? null));
            if ($sku === '' && $quantity === '') {
                continue;
            }
            $selection = $this->replacementSelectionFactory->create();
            $selection->setSku($sku)->setQuantity($quantity);
            $selections[] = $selection;
        }

        return $selections;
    }

    private function getScalarPostValue(string $key): ?string
    {
        $value = $this->getRequest()->getParam($key);

        return is_scalar($value) ? (string)$value : null;
    }

    /**
     * @param mixed $value
     */
    private function scalarToString($value): string
    {
        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * @param array<mixed> $returnRows
     * @param array<mixed> $replacementRows
     * @param mixed $customerNote
     * @param mixed $internalNote
     * @return array<string, mixed>
     */
    private function buildPersistableData(
        int $orderId,
        array $returnRows,
        array $replacementRows,
        $customerNote,
        $internalNote
    ): array {
        $safeReturnRows = [];
        foreach ($returnRows as $key => $row) {
            if (!is_array($row)) {
                continue;
            }
            $itemId = (int)($row['order_item_id'] ?? $key);
            if ($itemId <= 0) {
                continue;
            }
            $safeReturnRows[$itemId] = [
                'selected' => (string)($row['selected'] ?? '0') === '1' ? '1' : '0',
                'qty' => $this->scalarToString($row['qty'] ?? null),
                'reason_code' => $this->scalarToString($row['reason_code'] ?? null),
            ];
        }
        $safeReplacementRows = [];
        foreach ($replacementRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $safeReplacementRows[] = [
                'sku' => $this->scalarToString($row['sku'] ?? null),
                'qty' => $this->scalarToString($row['qty'] ?? null),
            ];
        }

        return [
            'order_id' => $orderId,
            'return_items' => $safeReturnRows,
            'replacement_items' => $safeReplacementRows,
            'customer_note' => is_scalar($customerNote) ? (string)$customerNote : '',
            'internal_note' => is_scalar($internalNote) ? (string)$internalNote : '',
        ];
    }
}
