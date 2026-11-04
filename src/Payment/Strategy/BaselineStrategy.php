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

/**
 * Strategy 1 — Baseline (intentionally unprotected).
 *
 * Demonstrates the classic read-modify-write race condition under concurrency.
 * No locks, no version checks. Uses a plain READ COMMITTED transaction
 * (PostgreSQL default isolation), which does NOT prevent concurrent reads
 * of the same stale balance snapshot.
 *
 * Under benchmark load with multiple workers and a shared order:
 *   - Two workers may both read remaining = R
 *   - Both pass the balance check (amount ≤ R)
 *   - Both commit, writing paid_price_cents = old + amount
 *   - One write silently overwrites the other (lost update)
 *   - Invariants I1, I2, I4 may all be violated
 *
 * This is the CONTROL group. Use it to prove that protection is necessary.
 */
final class BaselineStrategy implements PaymentStrategyInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private PaymentRepository $paymentRepository,
    ) {}

    public function getName(): string
    {
        return 'baseline';
    }

    public function processPayment(
        Order $order,
        User $user,
        int $amountCents,
        PaymentMethod $paymentMethod,
        string $idempotencyKey,
    ): PaymentResult {
        $this->em->beginTransaction();
        try {
            // I5: application-level idempotency check (racy under concurrency — intentional)
            if ($this->paymentRepository->findOneBy(['idempotencyKey' => $idempotencyKey]) !== null) {
                throw new IdempotencyConflictException($idempotencyKey);
            }

            if ($order->getStatus() !== OrderStatus::Open) {
                throw new OrderNotOpenException($order->getId(), $order->getStatus());
            }

            // I3: balance check — no lock held here; concurrent workers read the same stale value
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
            $this->em->commit();

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
