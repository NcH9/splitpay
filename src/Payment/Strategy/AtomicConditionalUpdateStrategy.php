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
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Strategy 5 — Atomic Conditional UPDATE.
 *
 * Reserves the payment amount in a SINGLE atomic SQL statement instead of
 * the read-then-write pattern used by other strategies:
 *
 *   UPDATE orders
 *   SET
 *     paid_price_cents = paid_price_cents + :amount,
 *     version          = version + 1,
 *     updated_at       = NOW(),
 *     status           = CASE
 *                          WHEN paid_price_cents + :amount >= full_price_cents THEN 'paid'
 *                          ELSE status
 *                        END
 *   WHERE id                             = :orderId
 *     AND status                         = 'open'
 *     AND paid_price_cents + :amount    <= full_price_cents
 *
 * Semantics:
 *   1 row affected → amount reserved; proceed to insert Payment record
 *   0 rows affected → balance insufficient OR order not open → OverpaymentException
 *
 * Why this is concurrency-safe:
 *   PostgreSQL's row-level write lock is implicitly held for the duration
 *   of the UPDATE statement. The check and the increment are a single atomic
 *   operation at the DB engine level — no other transaction can interleave.
 *   No application-level lock or Doctrine version tracking required.
 *
 * Performance:
 *   Highest throughput of all strategies. No blocking wait queues;
 *   conflicts are simply rejected (0 rows) rather than queued.
 */
final class AtomicConditionalUpdateStrategy implements PaymentStrategyInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private PaymentRepository $paymentRepository,
    ) {}

    public function getName(): string
    {
        return 'atomic';
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
            // I5: application-level idempotency pre-check
            if ($this->paymentRepository->findOneBy(['idempotencyKey' => $idempotencyKey]) !== null) {
                throw new IdempotencyConflictException($idempotencyKey);
            }

            // The WHERE clause on status ensures we also catch OrderNotOpen implicitly,
            // but we surface a meaningful exception instead of a generic "0 rows affected".
            if ($order->getStatus() !== OrderStatus::Open) {
                throw new OrderNotOpenException($order->getId(), $order->getStatus());
            }

            // Single atomic reservation statement.
            // Combining balance check + increment + status transition in one operation
            // eliminates the TOCTOU (time-of-check/time-of-use) window entirely.
            $affected = $this->em->getConnection()->executeStatement(
                <<<'SQL'
                    UPDATE orders
                    SET
                        paid_price_cents = paid_price_cents + :amount,
                        version          = version + 1,
                        updated_at       = NOW(),
                        status           = CASE
                                             WHEN paid_price_cents + :amount >= full_price_cents
                                             THEN 'paid'
                                             ELSE status
                                           END
                    WHERE id                            = :orderId
                      AND status                        = 'open'
                      AND paid_price_cents + :amount   <= full_price_cents
                    SQL,
                [
                    'amount'  => $amountCents,
                    'orderId' => $order->getId(),
                ],
            );

            if ($affected === 0) {
                // Either balance was insufficient or another concurrent request
                // just claimed the remaining capacity. Reject this payment.
                throw new OverpaymentException(
                    $order->getId(),
                    $amountCents,
                    $order->getRemainingCents(), // Note: in-memory value; real remaining may be even less
                );
            }

            // Atomic UPDATE succeeded — now record the Payment entity.
            // If this INSERT fails (e.g. duplicate idempotency_key race), the
            // catch block rolls back the entire transaction including the UPDATE.
            $payment = new Payment($amountCents, $paymentMethod, $idempotencyKey, $user, $order);
            $this->em->persist($payment);
            $this->em->flush(); // Only persists the new Payment; Order was updated via raw SQL
            $this->em->commit();

            // Sync the in-memory Order entity with the state now committed in the DB.
            // This keeps Doctrine's identity map consistent for any subsequent operations.
            $this->em->refresh($order);

            return new PaymentResult($payment, $this->getName());

        } catch (UniqueConstraintViolationException $e) {
            // A concurrent request inserted a Payment with the same idempotency_key
            // between our pre-check and our INSERT. Roll back (which also undoes the
            // atomic UPDATE) and surface a clean domain exception.
            $this->rollbackSafely();
            throw new IdempotencyConflictException($idempotencyKey);
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
