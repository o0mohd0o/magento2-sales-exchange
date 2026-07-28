<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Unit\Model;

use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\ResourceModel\DocumentLink;
use Magento\Framework\Model\AbstractModel;
use PHPUnit\Framework\TestCase;

/**
 * Verify the native-document audit ledger cannot be rewritten.
 */
class DocumentLinkResourceGuardTest extends TestCase
{
    public function testExistingDocumentLinkCannotBeSavedAgain(): void
    {
        $model = $this->createMock(AbstractModel::class);
        $model->method('getId')->willReturn(10);
        $resource = $this->resourceProbe();

        $this->expectException(InvariantViolationException::class);
        $resource->invokeBeforeSave($model);
    }

    public function testDocumentLinkCannotBeDeleted(): void
    {
        $model = $this->createMock(AbstractModel::class);
        $resource = $this->resourceProbe();

        $this->expectException(InvariantViolationException::class);
        $resource->invokeBeforeDelete($model);
    }

    /**
     * Expose protected hooks without constructing database services.
     */
    private function resourceProbe(): DocumentLink
    {
        return new class () extends DocumentLink {
            public function __construct()
            {
            }

            public function invokeBeforeSave(AbstractModel $model): DocumentLink
            {
                return $this->_beforeSave($model);
            }

            public function invokeBeforeDelete(AbstractModel $model): DocumentLink
            {
                return $this->_beforeDelete($model);
            }
        };
    }
}
