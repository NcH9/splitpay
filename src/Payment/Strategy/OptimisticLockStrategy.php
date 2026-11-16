<?php

declare(strict_types=1);

namespace App\Payment\Strategy;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\User;
use App\Enum\OrderStatus;
use App\Enum\PaymentMethod;
use App\Exception\IdempotencyConflictException;
use App\Exception\OptimisticLockExhaustedException;
use App\Exception\OrderNotOpenException;
use App\Exception\OverpaymentException;
use App\Payment\PaymentResult;
use App\Payment\PaymentStrategyInterface;
use App\Repository\PaymentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Strategy 3 — Optimistic Lock (version-based conflict detection + retry).
 *
 * Does NOT block concurrent requests. Instead it detects conflicts at commit time
 * by comparing Order.version against the value read at the start of the attempt.
 * When Doctrine sees the row was modified by another transaction (version mismatch),
 * it throws OptimisticLockException. A retry loop resets the closed EntityManager,
 * re-reads fresh state from the database, and tries again up to MAX_RETRIES.
 */
final class OptimisticLockStrategy implements PaymentStrategyInterface
{
    public const int MAX_RETRIES = 3;

    private EntityManagerInterface $em;

    public function __construct(
        private readonly ManagerRegistry $registry,
    ) {
        /** @var EntityManagerInterface $em */
        $em = $this->registry->getManager();
        $this->em = $em;
    }

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
            // Re-fetch entities using the active EntityManager
            if ($retries > 0) {
                $order = $this->em->find(Order::class, $orderId);
                $user  = $this->em->find(User::class, $userId);

                if ($order === null || $user === null) {
                    throw new \RuntimeException('Order or User disappeared during optimistic lock retry.');
                }
            }

            $this->em->beginTransaction();
            try {
                // I5: idempotency check with fresh DB state
                $paymentRepo = $this->em->getRepository(Payment::class);
                if ($paymentRepo->findOneBy(['idempotencyKey' => $idempotencyKey]) !== null) {
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
                $this->em->flush();
                $this->em->commit();

                return new PaymentResult($payment, $this->getName(), $retries);

            } catch (OptimisticLockException $e) {
                $this->rollbackSafely();

                ++$retries;
                if ($retries >= self::MAX_RETRIES) {
                    throw new OptimisticLockExhaustedException($orderId, $retries);
                }

                // When flush() throws, Doctrine permanently closes the EntityManager.
                // resetManager() reopens a fresh, functional EntityManager for the retry.
                /** @var EntityManagerInterface $em */
                $em = $this->registry->resetManager();
                $this->em = $em;
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
