<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use App\Benchmark\BenchmarkRunner;
use App\Benchmark\ConsistencyAuditor;
use App\Enum\OrderStatus;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Concurrency stress tests exercising real parallel HTTP requests against Nginx
 * to empirically verify invariant enforcement and race condition behavior.
 *
 * Connects directly to the live PostgreSQL instance shared with Nginx/PHP-FPM.
 */
final class ConcurrencyStressTest extends KernelTestCase
{
    private Connection $devDb;
    private ConsistencyAuditor $auditor;
    private BenchmarkRunner $runner;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->runner = $container->get(BenchmarkRunner::class);

        // Nginx runs against the dev database ('splitpay'). We connect directly
        // to that database to persist test scenarios and audit post-concurrency state.
        $this->devDb = DriverManager::getConnection([
            'driver'   => 'pdo_pgsql',
            'host'     => 'database',
            'port'     => 5432,
            'user'     => 'splitpay',
            'password' => 'secret',
            'dbname'   => 'splitpay',
        ]);
        $this->auditor = new ConsistencyAuditor($this->devDb);
    }

    private function createScenario(int $fullPriceCents): array
    {
        $this->devDb->executeStatement(
            'INSERT INTO users (name, created_at) VALUES (:name, NOW())',
            ['name' => 'Stress User ' . bin2hex(random_bytes(5))],
        );
        $userId = (int) $this->devDb->lastInsertId();

        $this->devDb->executeStatement(
            'INSERT INTO orders (name, full_price_cents, paid_price_cents, status, version, created_at, updated_at, user_id)
             VALUES (:name, :fullPrice, 0, :status, 1, NOW(), NOW(), :userId)',
            [
                'name' => 'Stress Order ' . bin2hex(random_bytes(5)),
                'fullPrice' => $fullPriceCents,
                'status' => OrderStatus::Open->value,
                'userId' => $userId,
            ],
        );
        $orderId = (int) $this->devDb->lastInsertId();

        return [$orderId, $userId];
    }

    public function testBaselineExposesRaceConditionUnderConcurrency(): void
    {
        // 3000 cents max (allows exactly 3 payments of 1000 cents)
        [$orderId, $userId] = $this->createScenario(3000);

        // 12 concurrent requests fired against an order with remaining balance for only 3
        $this->runner->run(
            strategy: 'baseline',
            orderId: $orderId,
            userId: $userId,
            totalRequests: 12,
            concurrency: 6,
            paymentAmountCents: 1000,
        );

        // Audit the order directly in the database where Nginx processed requests
        $audit = $this->auditor->audit($orderId);

        // Baseline must expose race condition: either overpayment (I1) or balance discrepancy (I2)
        self::assertTrue(
            $audit->isOverpaid || !$audit->isConsistent,
            sprintf(
                'Expected baseline to expose a race condition, but got paid=%d, actual=%d, overpaid=%s, consistent=%s',
                $audit->orderPaidPriceCents,
                $audit->actualPaymentSumCents,
                $audit->isOverpaid ? 'YES' : 'NO',
                $audit->isConsistent ? 'YES' : 'NO',
            ),
        );
    }

    public function testPessimisticStrictlyEnforcesInvariantsUnderConcurrency(): void
    {
        [$orderId, $userId] = $this->createScenario(3000);

        $result = $this->runner->run(
            strategy: 'pessimistic',
            orderId: $orderId,
            userId: $userId,
            totalRequests: 12,
            concurrency: 6,
            paymentAmountCents: 1000,
        );

        $audit = $this->auditor->audit($orderId);

        self::assertFalse($audit->isOverpaid, 'Invariant I1 violated: order was overpaid.');
        self::assertTrue($audit->isConsistent, 'Invariant I2 violated: paid price diverged from actual payment sum.');
        self::assertSame(3000, $audit->orderPaidPriceCents);
        self::assertSame(3000, $audit->actualPaymentSumCents);
        self::assertSame(3, $audit->paymentCount);
        self::assertSame(3, $result->status201Count);
        self::assertSame(0, $result->status5xxCount);
    }

    public function testOptimisticStrictlyEnforcesInvariantsUnderConcurrency(): void
    {
        [$orderId, $userId] = $this->createScenario(3000);

        $result = $this->runner->run(
            strategy: 'optimistic',
            orderId: $orderId,
            userId: $userId,
            totalRequests: 12,
            concurrency: 6,
            paymentAmountCents: 1000,
        );

        $audit = $this->auditor->audit($orderId);

        self::assertFalse($audit->isOverpaid, 'Invariant I1 violated: order was overpaid.');
        self::assertTrue($audit->isConsistent, 'Invariant I2 violated: paid price diverged from actual payment sum.');
        self::assertSame(3000, $audit->orderPaidPriceCents);
        self::assertSame(3000, $audit->actualPaymentSumCents);
        self::assertSame(3, $audit->paymentCount);
        self::assertSame(3, $result->status201Count);
        self::assertSame(0, $result->status5xxCount);
    }

    public function testMutexStrictlyEnforcesInvariantsUnderConcurrency(): void
    {
        [$orderId, $userId] = $this->createScenario(3000);

        $result = $this->runner->run(
            strategy: 'mutex',
            orderId: $orderId,
            userId: $userId,
            totalRequests: 12,
            concurrency: 6,
            paymentAmountCents: 1000,
        );

        $audit = $this->auditor->audit($orderId);

        self::assertFalse($audit->isOverpaid, 'Invariant I1 violated: order was overpaid.');
        self::assertTrue($audit->isConsistent, 'Invariant I2 violated: paid price diverged from actual payment sum.');
        self::assertSame(3000, $audit->orderPaidPriceCents);
        self::assertSame(3000, $audit->actualPaymentSumCents);
        self::assertSame(3, $audit->paymentCount);
        self::assertSame(3, $result->status201Count);
        self::assertSame(0, $result->status5xxCount);
    }

    public function testAtomicStrictlyEnforcesInvariantsUnderConcurrency(): void
    {
        [$orderId, $userId] = $this->createScenario(3000);

        $result = $this->runner->run(
            strategy: 'atomic',
            orderId: $orderId,
            userId: $userId,
            totalRequests: 12,
            concurrency: 6,
            paymentAmountCents: 1000,
        );

        $audit = $this->auditor->audit($orderId);

        self::assertFalse($audit->isOverpaid, 'Invariant I1 violated: order was overpaid.');
        self::assertTrue($audit->isConsistent, 'Invariant I2 violated: paid price diverged from actual payment sum.');
        self::assertSame(3000, $audit->orderPaidPriceCents);
        self::assertSame(3000, $audit->actualPaymentSumCents);
        self::assertSame(3, $audit->paymentCount);
        self::assertSame(3, $result->status201Count);
        self::assertSame(0, $result->status5xxCount);
    }
}
