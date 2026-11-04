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
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;

/**
 * Strategy 3 — Optimistic Lock (version-based conflict detection + retry).
 *
 * Does NOT block concurrent requests. Instead it detects conflicts at commit time
 * by comparing Order.version against the value read at the start of the attempt.
 * When Doctrine sees the row was modified by another transaction (version mismatch),
 * it throws OptimisticLockException. A retry loop re-reads fresh state and tries again.
 *
 * Key metrics for the benchmark:
 *   - retriesAttempted: how many conflicts were detected before success
 *   - Under low contention: 0 retries, throughput close to Baseline
 *   - Under high contention: many retries, potential exhaustion after MAX_RETRIES
 *
 * MAX_RETRIES = 3: balance between resilience and latency.
 */
final class OptimisticLockStrategy implements PaymentStrategyInterface
{
    public const int MAX_RETRIES = 3;

    public function __construct(
        private EntityManagerInterface $em,
        private PaymentRepository $paymentRepository,
    ) {}

    public function getName(): string
    {
        return 'optimistic';
    }

    public function processPayment(
        Order $order,
        User $user,
        int $amountCents,
        PaymentMethod $paymentMethod,
        string $idempotencyKey,
    ): PaymentResult {
        $orderId = $order->getId();
        $userId  = $user->getId();
        $retries = 0;

        while (true) {
            // On every retry, clear the identity map so we work with
            // freshly loaded entities (not stale cached versions).
            if ($retries > 0) {
                $this->em->clear();
                $order = $this->em->find(Order::class, $orderId);
                $user  = $this->em->find(User::class, $userId);

                if ($order === null || $user === null) {
                    throw new \RuntimeException('Order or User disappeared during optimistic lock retry.');
                }
            }

            $this->em->beginTransaction();
            try {
                // I5: idempotency — always check with fresh DB state
                if ($this->paymentRepository->findOneBy(['idempotencyKey' => $idempotencyKey]) !== null) {
                    throw new IdempotencyConflictException($idempotencyKey);
                }

                if ($order->getStatus() !== OrderStatus::Open) {
                    throw new OrderNotOpenException($order->getId(), $order->getStatus());
                }

                // I3: balance check against the freshly read order
                if ($amountCents > $order->getRemainingCents()) {
                    throw new OverpaymentException($order->getId(), $amountCents, $order->getRemainingCents());
                }

                $payment = new Payment($amountCents, $paymentMethod, $idempotencyKey, $user, $order);

                $order->setPaidPriceCents($order->getPaidPriceCents() + $amountCents);
                if ($order->isFullyPaid()) {
                    $order->setStatus(OrderStatus::Paid);
                }

                $this->em->persist($payment);

                // flush() issues: UPDATE orders … WHERE id = ? AND version = ?
                // If another transaction incremented version between our read and this flush,
                // Doctrine throws OptimisticLockException.
                $this->em->flush();
                $this->em->commit();

                return new PaymentResult($payment, $this->getName(), $retries);

            } catch (OptimisticLockException $e) {
                $this->rollbackSafely();
                // Clear EM so the next iteration starts completely fresh
                $this->em->clear();

                ++$retries;
                if ($retries >= self::MAX_RETRIES) {
                    throw new \RuntimeException(
                        sprintf(
                            'Payment failed after %d retries due to concurrent modifications on order #%d.',
                            $retries,
                            $orderId,
                        ),
                        0,
                        $e,
                    );
                }
                // Loop and retry with freshly loaded entities
            } catch (\Throwable $e) {
                $this->rollbackSafely();
                throw $e;
            }
        }
    }

    private function rollbackSafely(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->rollback();
        }
    }
}
