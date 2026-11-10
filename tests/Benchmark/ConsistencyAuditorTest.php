<?php

declare(strict_types=1);

namespace App\Tests\Benchmark;

use App\Benchmark\ConsistencyAuditor;
use App\Entity\Order;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ConsistencyAuditorTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private Connection $connection;
    private ConsistencyAuditor $auditor;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->auditor = $container->get(ConsistencyAuditor::class);
    }

    public function testAuditsConsistentOrder(): void
    {
        [$user, $order] = $this->createOrder(1000);
        $this->connection->executeStatement('UPDATE orders SET paid_price_cents = 250 WHERE id = :id', ['id' => $order->getId()]);
        $this->insertPayment($user->getId(), $order->getId(), 250);

        $result = $this->auditor->audit($order->getId());

        self::assertTrue($result->isConsistent);
        self::assertFalse($result->isOverpaid);
        self::assertSame(250, $result->actualPaymentSumCents);
        self::assertSame(1, $result->paymentCount);
        self::assertSame(0, $result->discrepancyCents);
    }

    public function testDetectsOverpaidOrder(): void
    {
        [$user, $order] = $this->createOrder(100);
        $this->connection->executeStatement('UPDATE orders SET paid_price_cents = 150 WHERE id = :id', ['id' => $order->getId()]);
        $this->insertPayment($user->getId(), $order->getId(), 150);

        $result = $this->auditor->audit($order->getId());

        self::assertTrue($result->isConsistent);
        self::assertTrue($result->isOverpaid);
        self::assertSame(150, $result->actualPaymentSumCents);
        self::assertSame(100, $result->orderFullPriceCents);
    }

    public function testDetectsPaidBalanceDiscrepancy(): void
    {
        [$user, $order] = $this->createOrder(1000);
        $this->insertPayment($user->getId(), $order->getId(), 250);

        $result = $this->auditor->audit($order->getId());

        self::assertFalse($result->isConsistent);
        self::assertFalse($result->isOverpaid);
        self::assertSame(0, $result->orderPaidPriceCents);
        self::assertSame(250, $result->actualPaymentSumCents);
        self::assertSame(250, $result->discrepancyCents);
    }

    /** @return array{User, Order} */
    private function createOrder(int $fullPriceCents): array
    {
        $user = new User('Auditor Test ' . bin2hex(random_bytes(5)));
        $order = new Order('Auditor Test Order', $fullPriceCents, $user);
        $this->entityManager->persist($user);
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return [$user, $order];
    }

    private function insertPayment(int $userId, int $orderId, int $amountCents): void
    {
        $this->connection->insert('payments', [
            'amount_cents' => $amountCents,
            'payment_method' => 'card',
            'idempotency_key' => 'audit-' . bin2hex(random_bytes(12)),
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'user_id' => $userId,
            'order_id' => $orderId,
        ]);
    }
}
