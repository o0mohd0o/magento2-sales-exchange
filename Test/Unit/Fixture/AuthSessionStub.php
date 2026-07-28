<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Unit\Fixture;

use Magento\Backend\Model\Auth\Session;
use Magento\User\Model\User;

/**
 * Expose Magento's documented magic getUser() method to PHPUnit mock builders.
 */
class AuthSessionStub extends Session
{
    /**
     * @return User|null
     */
    public function getUser(): ?User
    {
        return null;
    }
}
