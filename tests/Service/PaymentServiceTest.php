<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\Payment\ProcessPaymentRequest;
use App\Payment\StrategyRegistry;
use App\Service\PaymentService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests the PaymentService routing logic (order/user lookup + strategy resolution).
 * Strategy execution (the actual payment logic) is tested in Section 3.
 */
final class PaymentServiceTest extends KernelTestCase
{
    private PaymentService $paymentService;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->paymentService = static::getContainer()->get(PaymentService::class);
    }

    public function testPayThrowsNotFoundForMissingOrder(): void
    {
        $request = new ProcessPaymentRequest(
            amountCents:    1000,
            paymentMethod:  'card',
            idempotencyKey: 'svc-test-no-order',
            userId:         1,
            strategy:       'baseline',
        );

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Order #999999 not found.');

        $this->paymentService->pay(999999, $request);
    }

    public function testStrategyRegistryContainsAllFiveStrategies(): void
    {
        $registry = static::getContainer()->get(StrategyRegistry::class);

        $names = $registry->names();
        sort($names);

        self::assertSame(
            ['atomic', 'baseline', 'mutex', 'optimistic', 'pessimistic'],
            $names,
            'All 5 strategies must be registered.',
        );
    }

    public function testStrategyRegistryThrowsForUnknownName(): void
    {
        $registry = static::getContainer()->get(StrategyRegistry::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown payment strategy "nonexistent"');

        $registry->get('nonexistent');
    }
}
