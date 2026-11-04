<?php

declare(strict_types=1);

namespace App\Payment\Strategy;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\User;
use App\Enum\OrderStatus;
use App\Enum\PaymentMethod;
use App\Exception\IdempotencyConflictException;
use App\Exception\OrderNotOpenException;
use App\Exception\OverpaymentException;
use App\Payment\PaymentResult;
use App\Payment\PaymentStrategyInterface;
use App\Repository\PaymentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;

/**
 * Strategy 2 — Pessimistic Lock (database row-level write lock).
 *
 * Acquires an exclusive lock on the order row via SELECT … FOR UPDATE
 * before reading the balance. All concurrent requests for the same order
 * queue at the database level and execute serially.
 *
 * Correctness: guaranteed (all invariants enforced).
 * Throughput:  limited by lock contention on a single row (the hot order).
 * Latency:     increases with number of concurrent workers.
 *
 * Doctrine mapping: LockMode::PESSIMISTIC_WRITE
 * SQL equivalent:   SELECT … FROM orders WHERE id = ? FOR UPDATE
 */
final class PessimisticLockStrategy implements PaymentStrategyInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private PaymentRepository $paymentRepository,
    ) {}

    public function getName(): string
    {
        return 'pessimistic';
    }

    public function processPayment(
        Order $order,
        User $user,
        int $amountCents,
        PaymentMethod $paymentMethod,
        string $idempotencyKey,
    ): PaymentResult {
        $orderId = $order->getId();

        // Transaction must be open BEFORE acquiring the pessimistic lock.
        // PostgreSQL releases FOR UPDATE locks immediately if no transaction is active.
        $this->em->beginTransaction();
        try {
            // Re-fetch the order WITH an exclusive row-level write lock.
            // LockMode::PESSIMISTIC_WRITE bypasses the EM identity map cache
            // and executes SELECT … FOR UPDATE, blocking until the lock is granted.
            $order = $this->em->find(Order::class, $orderId, LockMode::PESSIMISTIC_WRITE);

            if ($order === null) {
                throw new \RuntimeException(sprintf('Order #%d disappeared during lock acquisition.', $orderId));
            }

            // I5: idempotency — safe to check here; we hold the row lock
            if ($this->paymentRepository->findOneBy(['idempotencyKey' => $idempotencyKey]) !== null) {
                throw new IdempotencyConflictException($idempotencyKey);
            }

            if ($order->getStatus() !== OrderStatus::Open) {
                throw new OrderNotOpenException($order->getId(), $order->getStatus());
            }

            // I3: balance check — safe; no other transaction can modify the row while we hold the lock
            if ($amountCents > $order->getRemainingCents()) {
                throw new OverpaymentException($order->getId(), $amountCents, $order->getRemainingCents());
            }

            $payment = new Payment($amountCents, $paymentMethod, $idempotencyKey, $user, $order);

            $order->setPaidPriceCents($order->getPaidPriceCents() + $amountCents);
            if ($order->isFullyPaid()) {
                $order->setStatus(OrderStatus::Paid);
            }

            $this->em->persist($payment);
            $this->em->flush();
            $this->em->commit(); // Releases the FOR UPDATE row lock

            return new PaymentResult($payment, $this->getName());
        } catch (\Throwable $e) {
            $this->rollbackSafely();
            throw $e;
        }
    }

    private function rollbackSafely(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->rollback();
        }
    }
}
