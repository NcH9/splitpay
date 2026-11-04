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
use Symfony\Component\Lock\LockFactory;

/**
 * Strategy 4 — Application Mutex (Symfony Lock component).
 *
 * Acquires a process-level mutex keyed on the order ID BEFORE entering
 * the critical section. All PHP workers that reach the same lock key will
 * block until the holder releases it, serializing execution at the
 * application layer rather than at the database layer.
 *
 * Lock key: "order:{orderId}"
 * TTL:       10 seconds (auto-release safety net; prevents deadlock on crash)
 *
 * Comparison with PessimisticLock:
 *   - PessimisticLock blocks at the DB row level (inside a transaction)
 *   - SymfonyLock blocks at the application level (before the transaction opens)
 *   - Both serialize access; difference matters for distributed environments
 *
 * Store: configured via LOCK_DSN in .env (default: flock on filesystem).
 * For distributed setups: redis://, postgresql://, or memcached://.
 */
final class SymfonyLockStrategy implements PaymentStrategyInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private PaymentRepository $paymentRepository,
        private LockFactory $lockFactory,
    ) {}

    public function getName(): string
    {
        return 'mutex';
    }

    public function processPayment(
        Order $order,
        User $user,
        int $amountCents,
        PaymentMethod $paymentMethod,
        string $idempotencyKey,
    ): PaymentResult {
        $lock = $this->lockFactory->createLock(
            resource:    sprintf('order:%d', $order->getId()),
            ttl:         10.0,
            autoRelease: true,
        );

        // Block until we acquire the mutex for this specific order.
        // All other PHP workers trying to pay this order will wait here.
        $lock->acquire(blocking: true);

        try {
            $this->em->beginTransaction();
            try {
                // I5: idempotency — safe; we hold the application-level mutex
                if ($this->paymentRepository->findOneBy(['idempotencyKey' => $idempotencyKey]) !== null) {
                    throw new IdempotencyConflictException($idempotencyKey);
                }

                if ($order->getStatus() !== OrderStatus::Open) {
                    throw new OrderNotOpenException($order->getId(), $order->getStatus());
                }

                // I3: balance check — safe; mutex prevents concurrent reads
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
        } finally {
            // Always release the mutex — even on exception — so the next
            // waiter can proceed rather than blocking until TTL expires.
            $lock->release();
        }
    }

    private function rollbackSafely(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->rollback();
        }
    }
}
