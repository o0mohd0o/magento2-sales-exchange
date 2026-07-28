<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Unit\Block\Adminhtml\Exchange;

use Bonlineco\SalesExchange\Block\Adminhtml\Exchange\View;
use Magento\Backend\Block\Widget\Container;
use Magento\Backend\Block\Widget\Context;
use PHPUnit\Framework\TestCase;

/**
 * Protect the admin container's constructor context from DI compile fatals.
 */
class ViewConstructorContractTest extends TestCase
{
    public function testFirstParameterMatchesParentWidgetContext(): void
    {
        $moduleParameter = (new \ReflectionMethod(View::class, '__construct'))
            ->getParameters()[0];
        $nativeParameter = (new \ReflectionMethod(
            Container::class,
            '__construct'
        ))->getParameters()[0];
        $moduleType = $moduleParameter->getType();
        $nativeType = $nativeParameter->getType();

        self::assertInstanceOf(\ReflectionNamedType::class, $moduleType);
        self::assertInstanceOf(\ReflectionNamedType::class, $nativeType);
        self::assertSame(Context::class, $moduleType->getName());
        self::assertSame($nativeType->getName(), $moduleType->getName());
        self::assertFalse($moduleType->allowsNull());
    }
}
