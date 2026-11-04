<?php

declare(strict_types=1);

namespace App\Tests\Payment\Strategy;

use App\Entity\Order;
use App\Entity\User;
use App\Enum\OrderStatus;
use App\Enum\PaymentMethod;
use App\Exception\IdempotencyConflictException;
use App\Exception\OverpaymentException;
use App\Payment\PaymentStrategyInterface;
use App\Payment\PaymentResult;
use App\Payment\Strategy\BaselineStrategy;
use App\Payment\Strategy\PessimisticLockStrategy;
use App\Payment\Strategy\OptimisticLockStrategy;
use App\Payment\Strategy\SymfonyLockStrategy;
use App\Payment\Strategy\AtomicConditionalUpdateStrategy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for all 5 PaymentStrategyInterface implementations.
 *
 * Tests run against the real splitpay_test PostgreSQL database.
 * Each helper creates unique entities (via uniqid) to avoid state leakage between tests.
 *
 * These tests verify SINGLE-REQUEST correctness only.
 * Concurrent correctness (the core research goal) is verified in Section 4 (benchmark).
 */
final class StrategyIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private BaselineStrategy $baseline;
    private PessimisticLockStrategy $pessimistic;
    private OptimisticLockStrategy $optimistic;
    private SymfonyLockStrategy $mutex;
    private AtomicConditionalUpdateStrategy $atomic;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->em          = $container->get(EntityManagerInterface::class);
        $this->baseline    = $container->get(BaselineStrategy::class);
        $this->pessimistic = $container->get(PessimisticLockStrategy::class);
        $this->optimistic  = $container->get(OptimisticLockStrategy::class);
        $this->mutex       = $container->get(SymfonyLockStrategy::class);
        $this->atomic      = $container->get(AtomicConditionalUpdateStrategy::class);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function createUserAndOrder(int $fullPriceCents): array
    {
        $user  = new User('User-' . uniqid());
        $order = new Order('Order-' . uniqid(), $fullPriceCents, $user);
        $this->em->persist($user);
        $this->em->persist($order);
        $this->em->flush();

        return [$user, $order];
    }

    private function freshOrder(Order $order): Order
    {
        $this->em->refresh($order);
        return $order;
    }

    private function key(string $prefix = 'k'): string
    {
        return $prefix . '-' . uniqid('', true);
    }

    // ─── Baseline ─────────────────────────────────────────────────────────────

    public function testBaselineProcessesValidPayment(): void
    {
        [$user, $order] = $this->createUserAndOrder(10_000);

        $result = $this->baseline->processPayment($order, $user, 4_000, PaymentMethod::Card, $this->key());

        self::assertInstanceOf(PaymentResult::class, $result);
        self::assertSame('baseline', $result->strategyName);
        self::assertSame(4_000, $result->payment->getAmountCents());
        self::assertSame(0, $result->retriesAttempted);

        $this->freshOrder($order);
        self::assertSame(4_000, $order->getPaidPriceCents());
        self::assertSame(OrderStatus::Open, $order->getStatus());
    }

    public function testBaselineMarksOrderPaidWhenFullyPaid(): void
    {
        [$user, $order] = $this->createUserAndOrder(5_000);

        $this->baseline->processPayment($order, $user, 5_000, PaymentMethod::Cash, $this->key());

        $this->freshOrder($order);
        self::assertSame(OrderStatus::Paid, $order->getStatus());
        self::assertSame(5_000, $order->getPaidPriceCents());
    }

    public function testBaselineRejectsOverpayment(): void
    {
        [$user, $order] = $this->createUserAndOrder(5_000);

        $this->expectException(OverpaymentException::class);
        $this->baseline->processPayment($order, $user, 9_999, PaymentMethod::Card, $this->key());
    }

    public function testBaselineRejectsDuplicateIdempotencyKey(): void
    {
        [$user, $order] = $this->createUserAndOrder(10_000);
        $key = $this->key('dup');

        $this->baseline->processPayment($order, $user, 1_000, PaymentMethod::Card, $key);

        // Balance updated in memory — still 9000 remaining
        $this->expectException(IdempotencyConflictException::class);
        $this->baseline->processPayment($order, $user, 1_000, PaymentMethod::Card, $key);
    }

    // ─── Pessimistic ──────────────────────────────────────────────────────────

    public function testPessimisticProcessesValidPayment(): void
    {
        [$user, $order] = $this->createUserAndOrder(10_000);

        $result = $this->pessimistic->processPayment($order, $user, 3_000, PaymentMethod::Cash, $this->key());

        self::assertSame('pessimistic', $result->strategyName);
        self::assertSame(3_000, $result->payment->getAmountCents());

        $this->freshOrder($order);
        self::assertSame(3_000, $order->getPaidPriceCents());
    }

    public function testPessimisticMarksOrderPaid(): void
    {
        [$user, $order] = $this->createUserAndOrder(2_000);

        $this->pessimistic->processPayment($order, $user, 2_000, PaymentMethod::Card, $this->key());

        $this->freshOrder($order);
        self::assertSame(OrderStatus::Paid, $order->getStatus());
    }

    public function testPessimisticRejectsOverpayment(): void
    {
        [$user, $order] = $this->createUserAndOrder(1_000);

        $this->expectException(OverpaymentException::class);
        $this->pessimistic->processPayment($order, $user, 1_001, PaymentMethod::Card, $this->key());
    }

    public function testPessimisticRejectsDuplicateKey(): void
    {
        [$user, $order] = $this->createUserAndOrder(10_000);
        $key = $this->key('dup');

        // First payment — OK
        $this->pessimistic->processPayment($order, $user, 1_000, PaymentMethod::Card, $key);
        // Re-fetch since pessimistic re-fetches inside; in-memory order may be stale
        $this->em->refresh($order);

        $this->expectException(IdempotencyConflictException::class);
        $this->pessimistic->processPayment($order, $user, 1_000, PaymentMethod::Card, $key);
    }

    // ─── Optimistic ───────────────────────────────────────────────────────────

    public function testOptimisticProcessesValidPayment(): void
    {
        [$user, $order] = $this->createUserAndOrder(10_000);

        $result = $this->optimistic->processPayment($order, $user, 7_000, PaymentMethod::Card, $this->key());

        self::assertSame('optimistic', $result->strategyName);
        self::assertSame(7_000, $result->payment->getAmountCents());
        self::assertSame(0, $result->retriesAttempted);

        $this->freshOrder($order);
        self::assertSame(7_000, $order->getPaidPriceCents());
    }

    public function testOptimisticMarksOrderPaid(): void
    {
        [$user, $order] = $this->createUserAndOrder(3_000);

        $this->optimistic->processPayment($order, $user, 3_000, PaymentMethod::Cash, $this->key());

        $this->freshOrder($order);
        self::assertSame(OrderStatus::Paid, $order->getStatus());
    }

    public function testOptimisticRejectsOverpayment(): void
    {
        [$user, $order] = $this->createUserAndOrder(1_000);

        $this->expectException(OverpaymentException::class);
        $this->optimistic->processPayment($order, $user, 1_001, PaymentMethod::Card, $this->key());
    }

    public function testOptimisticRejectsDuplicateKey(): void
    {
        [$user, $order] = $this->createUserAndOrder(10_000);
        $key = $this->key('dup');

        $this->optimistic->processPayment($order, $user, 500, PaymentMethod::Cash, $key);

        $this->em->refresh($order);

        $this->expectException(IdempotencyConflictException::class);
        $this->optimistic->processPayment($order, $user, 500, PaymentMethod::Cash, $key);
    }

    // ─── Mutex (SymfonyLock) ───────────────────────────────────────────────────

    public function testMutexProcessesValidPayment(): void
    {
        [$user, $order] = $this->createUserAndOrder(10_000);

        $result = $this->mutex->processPayment($order, $user, 2_500, PaymentMethod::Card, $this->key());

        self::assertSame('mutex', $result->strategyName);
        self::assertSame(2_500, $result->payment->getAmountCents());

        $this->freshOrder($order);
        self::assertSame(2_500, $order->getPaidPriceCents());
    }

    public function testMutexMarksOrderPaid(): void
    {
        [$user, $order] = $this->createUserAndOrder(4_000);

        $this->mutex->processPayment($order, $user, 4_000, PaymentMethod::Cash, $this->key());

        $this->freshOrder($order);
        self::assertSame(OrderStatus::Paid, $order->getStatus());
    }

    public function testMutexRejectsOverpayment(): void
    {
        [$user, $order] = $this->createUserAndOrder(100);

        $this->expectException(OverpaymentException::class);
        $this->mutex->processPayment($order, $user, 101, PaymentMethod::Card, $this->key());
    }

    public function testMutexRejectsDuplicateKey(): void
    {
        [$user, $order] = $this->createUserAndOrder(10_000);
        $key = $this->key('dup');

        $this->mutex->processPayment($order, $user, 1_000, PaymentMethod::Card, $key);

        $this->expectException(IdempotencyConflictException::class);
        $this->mutex->processPayment($order, $user, 1_000, PaymentMethod::Card, $key);
    }

    // ─── Atomic ───────────────────────────────────────────────────────────────

    public function testAtomicProcessesValidPayment(): void
    {
        [$user, $order] = $this->createUserAndOrder(10_000);

        $result = $this->atomic->processPayment($order, $user, 6_000, PaymentMethod::Card, $this->key());

        self::assertSame('atomic', $result->strategyName);
        self::assertSame(6_000, $result->payment->getAmountCents());

        // After atomic, em->refresh() was called inside the strategy
        self::assertSame(6_000, $order->getPaidPriceCents());
    }

    public function testAtomicMarksOrderPaid(): void
    {
        [$user, $order] = $this->createUserAndOrder(5_000);

        $this->atomic->processPayment($order, $user, 5_000, PaymentMethod::Cash, $this->key());

        // The strategy calls em->refresh($order) internally
        self::assertSame(OrderStatus::Paid, $order->getStatus());
    }

    public function testAtomicRejectsOverpayment(): void
    {
        [$user, $order] = $this->createUserAndOrder(500);

        $this->expectException(OverpaymentException::class);
        $this->atomic->processPayment($order, $user, 501, PaymentMethod::Card, $this->key());
    }

    public function testAtomicRejectsDuplicateKey(): void
    {
        [$user, $order] = $this->createUserAndOrder(10_000);
        $key = $this->key('dup');

        $this->atomic->processPayment($order, $user, 500, PaymentMethod::Cash, $key);

        $this->expectException(IdempotencyConflictException::class);
        $this->atomic->processPayment($order, $user, 500, PaymentMethod::Cash, $key);
    }

    public function testAtomicVersionIncrementedOnSuccess(): void
    {
        [$user, $order] = $this->createUserAndOrder(10_000);
        $versionBefore = $order->getVersion();

        $this->atomic->processPayment($order, $user, 1_000, PaymentMethod::Card, $this->key());

        // Atomic strategy increments version via raw SQL; refresh() syncs it
        self::assertGreaterThan($versionBefore, $order->getVersion());
    }
}
