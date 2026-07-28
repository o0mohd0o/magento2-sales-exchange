<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Unit\Model\ReplacementOrder;

use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\ReplacementOrder\ExecutionContext;
use Bonlineco\SalesExchange\Model\ReplacementOrder\Marker;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
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
    ): CartInterface
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
