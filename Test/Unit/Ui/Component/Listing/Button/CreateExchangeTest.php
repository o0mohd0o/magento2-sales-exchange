<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Unit\Ui\Component\Listing\Button;

use Bonlineco\SalesExchange\Api\ConfigInterface;
use Bonlineco\SalesExchange\Model\Workflow\AdminActionMap;
use Bonlineco\SalesExchange\Ui\Component\Listing\Button\CreateExchange;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\UrlInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Verify the exchange-grid creation action is visible only when usable.
 */
class CreateExchangeTest extends TestCase
{
    private ConfigInterface&MockObject $config;

    private AuthorizationInterface&MockObject $authorization;

    private UrlInterface&MockObject $urlBuilder;

    private Json $json;

    private StoreManagerInterface&MockObject $storeManager;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigInterface::class);
        $this->authorization = $this->createMock(AuthorizationInterface::class);
        $this->urlBuilder = $this->createMock(UrlInterface::class);
        $this->json = new Json();
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
    }

    public function testEnabledAuthorizedActionLinksToOrderSelectionPage(): void
    {
        $this->expectStoreEnablement([1 => true]);
        $this->authorization->expects(self::exactly(2))
            ->method('isAllowed')
            ->willReturnMap([
                [AdminActionMap::ACL_CREATE, null, true],
                [AdminActionMap::ACL_SALES_ORDER_VIEW, null, true],
            ]);
        $this->urlBuilder->expects(self::once())
            ->method('getUrl')
            ->with('salesexchange/exchange/new')
            ->willReturn('https://admin.example.test/salesexchange/exchange/new');

        $button = $this->createButton()->getButtonData();

        self::assertSame('Create Exchange', (string)$button['label']);
        self::assertSame('primary', $button['class']);
        self::assertSame(10, $button['sort_order']);
        self::assertSame(AdminActionMap::ACL_CREATE, $button['aclResource']);
        self::assertSame(
            'location.href = "https:\/\/admin.example.test\/salesexchange\/exchange\/new";',
            $button['on_click']
        );
    }

    public function testDisabledFeatureHidesActionWithoutAuthorizationOrUrlWork(): void
    {
        $this->expectStoreEnablement([1 => false]);
        $this->authorization->expects(self::never())->method('isAllowed');
        $this->urlBuilder->expects(self::never())->method('getUrl');

        self::assertSame([], $this->createButton()->getButtonData());
    }

    public function testMissingCreatePermissionHidesAction(): void
    {
        $this->expectStoreEnablement([1 => true]);
        $this->authorization->expects(self::once())
            ->method('isAllowed')
            ->with(AdminActionMap::ACL_CREATE)
            ->willReturn(false);
        $this->urlBuilder->expects(self::never())->method('getUrl');

        self::assertSame([], $this->createButton()->getButtonData());
    }

    public function testMissingSalesOrderViewPermissionHidesAction(): void
    {
        $this->expectStoreEnablement([1 => true]);
        $this->authorization->expects(self::exactly(2))
            ->method('isAllowed')
            ->willReturnMap([
                [AdminActionMap::ACL_CREATE, null, true],
                [AdminActionMap::ACL_SALES_ORDER_VIEW, null, false],
            ]);
        $this->urlBuilder->expects(self::never())->method('getUrl');

        self::assertSame([], $this->createButton()->getButtonData());
    }

    public function testStoreScopedEnablementShowsActionWhenDefaultIsDisabled(): void
    {
        $this->expectStoreEnablement([
            1 => false,
            7 => true,
        ]);
        $this->authorization->method('isAllowed')->willReturn(true);
        $this->urlBuilder->method('getUrl')
            ->willReturn('https://admin.example.test/salesexchange/exchange/new');

        $button = $this->createButton()->getButtonData();

        self::assertSame('Create Exchange', (string)$button['label']);
        self::assertSame(AdminActionMap::ACL_CREATE, $button['aclResource']);
    }

    public function testInactiveEnabledStoreDoesNotExposeAction(): void
    {
        $this->expectStoreEnablement([7 => true], [7]);
        $this->authorization->expects(self::never())->method('isAllowed');
        $this->urlBuilder->expects(self::never())->method('getUrl');

        self::assertSame([], $this->createButton()->getButtonData());
    }

    public function testGeneratedUrlIsJsonEncodedBeforeEmbedding(): void
    {
        $url = "https://admin.example.test/o'ne\\two</script>";
        $this->expectStoreEnablement([1 => true]);
        $this->authorization->method('isAllowed')->willReturn(true);
        $this->urlBuilder->method('getUrl')->willReturn($url);

        $button = $this->createButton()->getButtonData();

        self::assertSame(
            sprintf('location.href = %s;', $this->json->serialize($url)),
            $button['on_click']
        );
        self::assertStringContainsString('<\/script>', $button['on_click']);
    }

    private function createButton(): CreateExchange
    {
        return new CreateExchange(
            $this->config,
            $this->authorization,
            $this->urlBuilder,
            $this->json,
            $this->storeManager
        );
    }

    /**
     * @param array<int, bool> $enabledByStoreId
     * @param int[] $inactiveStoreIds
     */
    private function expectStoreEnablement(
        array $enabledByStoreId,
        array $inactiveStoreIds = []
    ): void {
        $stores = [];
        $returnMap = [];
        foreach ($enabledByStoreId as $storeId => $isEnabled) {
            $store = $this->createMock(StoreInterface::class);
            $store->method('getId')->willReturn($storeId);
            $isActive = !in_array($storeId, $inactiveStoreIds, true);
            $store->method('getIsActive')->willReturn($isActive ? 1 : 0);
            $stores[] = $store;
            if ($isActive) {
                $returnMap[] = [$storeId, $isEnabled];
            }
        }

        $this->storeManager->expects(self::once())
            ->method('getStores')
            ->willReturn($stores);
        $this->config->expects(self::exactly(count($returnMap)))
            ->method('isEnabled')
            ->willReturnMap($returnMap);
    }
}
