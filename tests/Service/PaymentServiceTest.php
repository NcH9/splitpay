<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\Payment\ProcessPaymentRequest;
use App\Entity\Order;
use App\Entity\User;
use App\Enum\OrderStatus;
use App\Payment\StrategyRegistry;
use App\Service\PaymentService;
use Doctrine\ORM\EntityManagerInterface;
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

    public function testPayWithOptimisticRetryResolvesUpdatedOrderForPostProcessing(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User('Opt Retry User ' . uniqid());
        $order = new Order('Opt Retry Order ' . uniqid(), 10000, $user);
        $em->persist($user);
        $em->persist($order);
        $em->flush();

        // Advance version & paid amount directly in DB while keeping version=1 in EM Identity Map
        $em->getConnection()->executeStatement(
            'UPDATE orders SET paid_price_cents = 5000, version = version + 1 WHERE id = :id',
            ['id' => $order->getId()],
        );

        $request = new ProcessPaymentRequest(
            amountCents:    5000,
            paymentMethod:  'card',
            idempotencyKey: 'svc-opt-retry-' . bin2hex(random_bytes(8)),
            userId:         (int) $user->getId(),
            strategy:       'optimistic',
        );

        $result = $this->paymentService->pay((int) $order->getId(), $request);

        self::assertSame(1, $result->retriesAttempted);
        self::assertSame(10000, $result->payment->getOrder()?->getPaidPriceCents());
        self::assertSame(OrderStatus::Paid, $result->payment->getOrder()?->getStatus());
        self::assertFalse($order->isFullyPaid(), 'Original pre-retry detached Order remains stale, proving resolvedOrder is needed.');
    }
}
