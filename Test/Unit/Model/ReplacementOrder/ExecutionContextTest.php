<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Unit\Model\ReplacementOrder;

use Bonlineco\SalesExchange\Api\Data\ReplacementItemInterface;
use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\ReplacementOrder\ExecutionContext;
use Bonlineco\SalesExchange\Model\ReplacementOrder\Marker;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\OrderInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ExecutionContextTest extends TestCase
{
    private const INTENT_HASH =
        'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const OTHER_INTENT_HASH =
        'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testOnlyMarkedQuoteAndItsRepositoryReloadAreTrusted(): void
    {
        $context = new ExecutionContext();
        $trusted = $this->quote(41);
        $reload = $this->quote('41');
        $spoof = $this->quote(42);

        $context->execute(
            7,
            self::INTENT_HASH,
            static function () use ($context, $trusted, $reload, $spoof): void {
                $context->markQuote($trusted);

                self::assertTrue($context->isActiveFor(7, self::INTENT_HASH));
                self::assertTrue($context->isTrustedQuote($trusted));
                self::assertTrue($context->isTrustedQuote($reload));
                self::assertFalse($context->isTrustedQuote($spoof));
            }
        );

        self::assertFalse($context->isActiveFor(7, self::INTENT_HASH));
        self::assertFalse($context->isTrustedQuote($trusted));
    }

    public function testUnpersistedQuoteRequiresExactObjectIdentity(): void
    {
        $context = new ExecutionContext();
        $trusted = $this->quote(null);
        $spoof = $this->quote(null);

        $context->execute(
            7,
            self::INTENT_HASH,
            static function () use ($context, $trusted, $spoof): void {
                $context->markQuote($trusted);
                self::assertTrue($context->isTrustedQuote($trusted));
                self::assertFalse($context->isTrustedQuote($spoof));
            }
        );
    }

    public function testSameIdReloadRequiresExactActiveIntentMarkers(): void
    {
        $context = new ExecutionContext();
        $trusted = $this->quote(41);
        $missingMarkers = $this->quote(41, null, null);
        $wrongExchange = $this->quote(41, 8, self::INTENT_HASH);
        $wrongIntent = $this->quote(41, 7, self::OTHER_INTENT_HASH);
        $exactObjectWithoutMarkers = $this->quote(42, null, null);

        $context->execute(
            7,
            self::INTENT_HASH,
            static function () use (
                $context,
                $trusted,
                $missingMarkers,
                $wrongExchange,
                $wrongIntent,
                $exactObjectWithoutMarkers
            ): void {
                $context->markQuote($trusted);
                self::assertFalse(
                    $context->isTrustedQuote($missingMarkers)
                );
                self::assertFalse(
                    $context->isTrustedQuote($wrongExchange)
                );
                self::assertFalse(
                    $context->isTrustedQuote($wrongIntent)
                );
            }
        );
        $context->execute(
            7,
            self::INTENT_HASH,
            static function () use (
                $context,
                $exactObjectWithoutMarkers
            ): void {
                $context->markQuote($exactObjectWithoutMarkers);
                self::assertTrue(
                    $context->isTrustedQuote($exactObjectWithoutMarkers)
                );
            }
        );
    }

    public function testNestedIntentIsRejectedWithoutClearingOuterTrust(): void
    {
        $context = new ExecutionContext();
        $trusted = $this->quote(41);

        $context->execute(
            7,
            self::INTENT_HASH,
            static function () use ($context, $trusted): void {
                $context->markQuote($trusted);
                try {
                    $context->execute(
                        8,
                        self::OTHER_INTENT_HASH,
                        static fn (): bool => true
                    );
                    self::fail('A nested replacement-order intent must be rejected.');
                } catch (InvariantViolationException $exception) {
                    self::assertTrue($context->isTrustedQuote($trusted));
                    self::assertTrue($context->isActiveFor(7, self::INTENT_HASH));
                }
            }
        );
    }

    public function testContextIsClearedWhenCallbackThrows(): void
    {
        $context = new ExecutionContext();
        $trusted = $this->quote(41);

        try {
            $context->execute(
                7,
                self::INTENT_HASH,
                static function () use ($context, $trusted): void {
                    $context->markQuote($trusted);
                    self::throwNativeQuoteFailure();
                }
            );
            self::fail('The test callback must throw.');
        } catch (\RuntimeException $exception) {
            self::assertSame('native quote failure', $exception->getMessage());
        }

        self::assertFalse($context->isActiveFor(7, self::INTENT_HASH));
        self::assertFalse($context->isTrustedQuote($trusted));
    }

    public function testFrozenUnitPricesAreBoundAndClearedWithIntent(): void
    {
        $context = new ExecutionContext();

        $context->execute(
            7,
            self::INTENT_HASH,
            static function () use ($context): void {
                self::assertSame(
                    '12999.0000',
                    $context->getFrozenUnitPrice(71)
                );
                self::assertNull($context->getFrozenUnitPrice(72));
            },
            [[
                ReplacementItemInterface::ENTITY_ID => 71,
                ReplacementItemInterface::PRODUCT_ID => 21,
                ReplacementItemInterface::SKU => 'replacement-sku',
                ReplacementItemInterface::NAME => 'Replacement',
                ReplacementItemInterface::QTY => '1.0000',
                ReplacementItemInterface::UNIT_PRICE_AMOUNT => '12999.0000',
                ReplacementItemInterface::ROW_TOTAL_AMOUNT => '12999.0000',
            ]]
        );

        self::assertNull($context->getFrozenUnitPrice(71));
    }

    public function testFinalReloadedQuoteAndSavedOrderStayBoundUntilCommit(): void
    {
        $context = new ExecutionContext();
        $preparedQuote = $this->quote(41);
        $submittedQuote = $this->quote('41');
        $order = $this->createStub(OrderInterface::class);
        $order->method('getEntityId')->willReturn(200);
        $prePlaceCalls = 0;
        $postSaveCalls = 0;

        $context->execute(
            7,
            self::INTENT_HASH,
            static function () use (
                $context,
                $preparedQuote,
                $submittedQuote,
                $order,
                &$prePlaceCalls,
                &$postSaveCalls
            ): void {
                $context->setPreSubmitValidator(
                    static function (Quote $candidate) use (
                        $submittedQuote
                    ): void {
                        self::assertSame($submittedQuote, $candidate);
                    }
                );
                $context->setPrePlaceOrderValidator(
                    static function (
                        Quote $candidateQuote,
                        OrderInterface $candidateOrder
                    ) use (
                        $submittedQuote,
                        $order,
                        &$prePlaceCalls
                    ): void {
                        ++$prePlaceCalls;
                        self::assertSame(
                            $submittedQuote,
                            $candidateQuote
                        );
                        self::assertSame($order, $candidateOrder);
                    }
                );
                $context->setPostSaveOrderValidator(
                    static function (OrderInterface $candidate) use (
                        $order,
                        &$postSaveCalls
                    ): string {
                        ++$postSaveCalls;
                        self::assertSame($order, $candidate);

                        return str_repeat('b', 64);
                    }
                );
                $context->markQuote($preparedQuote);
                $context->validateBeforeSubmit($submittedQuote);
                $context->validateBeforePlace($order);
                self::assertTrue($context->isTrustedOrder($order));
                $context->validateBeforePlace($order);
                $context->validateAfterSave($order);
                $context->validateBeforeCommit(200, $order);
            }
        );

        self::assertSame(2, $prePlaceCalls);
        self::assertSame(2, $postSaveCalls);
        self::assertFalse($context->isTrustedOrder($order));
    }

    private static function throwNativeQuoteFailure(): void
    {
        throw new \RuntimeException('native quote failure');
    }

    public function testQuoteCannotBeMarkedOutsideAnActiveIntent(): void
    {
        $this->expectException(InvariantViolationException::class);
        (new ExecutionContext())->markQuote($this->quote(41));
    }

    public function testSecondQuoteCannotReplaceMarkedQuote(): void
    {
        $context = new ExecutionContext();
        $this->expectException(InvariantViolationException::class);

        $context->execute(
            7,
            self::INTENT_HASH,
            function () use ($context): void {
                $context->markQuote($this->quote(41));
                $context->markQuote($this->quote(42));
            }
        );
    }

    /**
     * @dataProvider invalidIntentProvider
     */
    #[DataProvider('invalidIntentProvider')]
    public function testInvalidIntentCannotActivate(int $exchangeId, string $intentHash): void
    {
        $this->expectException(InvariantViolationException::class);
        (new ExecutionContext())->execute(
            $exchangeId,
            $intentHash,
            static fn (): bool => true
        );
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function invalidIntentProvider(): array
    {
        return [
            'invalid exchange' => [0, self::INTENT_HASH],
            'caller text' => [7, 'caller-controlled'],
            'uppercase hash' => [7, strtoupper(self::INTENT_HASH)],
        ];
    }

    /**
     * @param int|string|null $id
     */
    private function quote(
        $id,
        ?int $exchangeId = 7,
        ?string $intentHash = self::INTENT_HASH
    ): Quote
    {
        /** @var Quote $quote */
        $quote = (new \ReflectionClass(Quote::class))
            ->newInstanceWithoutConstructor();
        $quote->setId($id);
        if ($exchangeId !== null) {
            $quote->setData(Marker::EXCHANGE_ID, $exchangeId);
        }
        if ($intentHash !== null) {
            $quote->setData(Marker::INTENT_HASH, $intentHash);
        }

        return $quote;
    }
}
