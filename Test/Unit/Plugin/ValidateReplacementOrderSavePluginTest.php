<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Bonlineco\SalesExchange\Test\Unit\Plugin;

use Bonlineco\SalesExchange\Exception\InvariantViolationException;
use Bonlineco\SalesExchange\Model\ReplacementOrder\ExecutionContext;
use Bonlineco\SalesExchange\Model\ReplacementOrder\Marker;
use Bonlineco\SalesExchange\Plugin\ValidateReplacementOrderSavePlugin;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderRepository;
use PHPUnit\Framework\TestCase;

class ValidateReplacementOrderSavePluginTest extends TestCase
{
    private const INTENT_HASH =
        'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testActiveReplacementIsValidatedAfterPlaceEvents(): void
    {
        $context = new ExecutionContext();
        $order = $this->order();
        $saved = false;
        $prePlaceCalls = 0;
        $postSaveCalls = 0;

        $context->execute(
            7,
            self::INTENT_HASH,
            function () use (
                $context,
                $order,
                &$saved,
                &$prePlaceCalls,
                &$postSaveCalls
            ): void {
                $quote = $this->quote();
                $context->setPreSubmitValidator(
                    static function (Quote $candidate): void {
                        unset($candidate);
                    }
                );
                $context->setPrePlaceOrderValidator(
                    static function (
                        Quote $submittedQuote,
                        OrderInterface $candidate
                    ) use ($order, &$prePlaceCalls): void {
                        unset($submittedQuote);
                        ++$prePlaceCalls;
                        self::assertSame($order, $candidate);
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
                $context->markQuote($quote);
                $context->validateBeforeSubmit($quote);
                $context->validateBeforePlace($order);
                $result = $this->plugin($context)->aroundSave(
                    $this->createMock(OrderRepository::class),
                    static function (OrderInterface $candidate) use (
                        &$saved
                    ): OrderInterface {
                        $saved = true;

                        return $candidate;
                    },
                    $order
                );

                self::assertSame($order, $result);
            }
        );

        self::assertTrue($saved);
        self::assertSame(2, $prePlaceCalls);
        self::assertSame(1, $postSaveCalls);
    }

    public function testValidationFailurePreventsRepositorySave(): void
    {
        $context = new ExecutionContext();
        $order = $this->order();
        $saved = false;

        try {
            $context->execute(
                7,
                self::INTENT_HASH,
                function () use ($context, $order, &$saved): void {
                    $quote = $this->quote();
                    $calls = 0;
                    $context->setPreSubmitValidator(
                        static function (Quote $candidate): void {
                            unset($candidate);
                        }
                    );
                    $context->setPrePlaceOrderValidator(
                        static function () use (&$calls): void {
                            ++$calls;
                            if ($calls > 1) {
                                throw new InvariantViolationException(
                                    __('converted drift')
                                );
                            }
                        }
                    );
                    $context->setPostSaveOrderValidator(
                        static function (): string {
                            return str_repeat('b', 64);
                        }
                    );
                    $context->markQuote($quote);
                    $context->validateBeforeSubmit($quote);
                    $context->validateBeforePlace($order);
                    $this->plugin($context)->aroundSave(
                        $this->createMock(OrderRepository::class),
                        static function (OrderInterface $candidate) use (
                            &$saved
                        ): OrderInterface {
                            $saved = true;

                            return $candidate;
                        },
                        $order
                    );
                }
            );
            self::fail('Converted drift must stop the repository save.');
        } catch (InvariantViolationException $exception) {
            self::assertSame('converted drift', $exception->getMessage());
        }

        self::assertFalse($saved);
    }

    public function testPostSaveFailureEscapesBeforeOuterCommit(): void
    {
        $context = new ExecutionContext();
        $order = $this->order();
        $saved = false;

        try {
            $context->execute(
                7,
                self::INTENT_HASH,
                function () use ($context, $order, &$saved): void {
                    $quote = $this->quote();
                    $context->setPreSubmitValidator(
                        static function (): void {
                        }
                    );
                    $context->setPrePlaceOrderValidator(
                        static function (): void {
                        }
                    );
                    $context->setPostSaveOrderValidator(
                        static function (): string {
                            throw new InvariantViolationException(
                                __('persisted drift')
                            );
                        }
                    );
                    $context->markQuote($quote);
                    $context->validateBeforeSubmit($quote);
                    $context->validateBeforePlace($order);
                    $this->plugin($context)->aroundSave(
                        $this->createMock(OrderRepository::class),
                        static function (
                            OrderInterface $candidate
                        ) use (&$saved): OrderInterface {
                            $saved = true;

                            return $candidate;
                        },
                        $order
                    );
                }
            );
            self::fail('Persisted drift must escape the repository plugin.');
        } catch (InvariantViolationException $exception) {
            self::assertSame(
                'persisted drift',
                $exception->getMessage()
            );
        }

        self::assertTrue($saved);
    }

    public function testClearedMarkersCannotBypassActiveValidation(): void
    {
        $context = new ExecutionContext();
        $order = $this->order();
        $saved = false;

        $this->expectException(InvariantViolationException::class);
        try {
            $context->execute(
                7,
                self::INTENT_HASH,
                function () use ($context, $order, &$saved): void {
                    $quote = $this->quote();
                    $context->setPreSubmitValidator(
                        static function (): void {
                        }
                    );
                    $context->setPrePlaceOrderValidator(
                        static function (
                            Quote $submittedQuote,
                            OrderInterface $candidate
                        ): void {
                            unset($submittedQuote);
                            if ((int)$candidate->getData(
                                Marker::EXCHANGE_ID
                            ) !== 7) {
                                throw new InvariantViolationException(
                                    __('missing marker')
                                );
                            }
                        }
                    );
                    $context->setPostSaveOrderValidator(
                        static function (): string {
                            return str_repeat('b', 64);
                        }
                    );
                    $context->markQuote($quote);
                    $context->validateBeforeSubmit($quote);
                    $context->validateBeforePlace($order);
                    $order->unsetData(Marker::EXCHANGE_ID);
                    $order->unsetData(Marker::INTENT_HASH);
                    $this->plugin($context)->aroundSave(
                        $this->createMock(OrderRepository::class),
                        static function (
                            OrderInterface $candidate
                        ) use (&$saved): OrderInterface {
                            $saved = true;

                            return $candidate;
                        },
                        $order
                    );
                }
            );
        } finally {
            self::assertFalse($saved);
        }
    }

    private function plugin(
        ExecutionContext $context
    ): ValidateReplacementOrderSavePlugin {
        return new ValidateReplacementOrderSavePlugin($context);
    }

    private function order(): Order
    {
        /** @var Order $order */
        $order = (new \ReflectionClass(Order::class))
            ->newInstanceWithoutConstructor();
        $order->setData(Marker::EXCHANGE_ID, 7);
        $order->setData(Marker::INTENT_HASH, self::INTENT_HASH);

        return $order;
    }

    private function quote(): Quote
    {
        /** @var Quote $quote */
        $quote = (new \ReflectionClass(Quote::class))
            ->newInstanceWithoutConstructor();
        $quote->setId(41);
        $quote->setData(Marker::EXCHANGE_ID, 7);
        $quote->setData(Marker::INTENT_HASH, self::INTENT_HASH);

        return $quote;
    }
}
