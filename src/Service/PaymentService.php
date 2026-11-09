<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\Payment\ProcessPaymentRequest;
use App\Enum\PaymentMethod;
use App\Exception\IdempotencyConflictException;
use App\Message\OrderFullyPaidEvent;
use App\Message\PaymentReceivedEvent;
use App\Payment\Idempotency\IdempotencyFilterInterface;
use App\Payment\PaymentResult;
use App\Payment\StrategyRegistry;
use App\Repository\OrderRepository;
use App\Repository\UserRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Thin orchestrator for the payment flow.
 *
 * Responsibilities:
 *   1. Intercept duplicate requests using fast Redis-based idempotency pre-filtering (Stripe pattern).
 *   2. Load the Order and User entities (throws HTTP 404 if either is missing).
 *   3. Resolve the requested PaymentStrategyInterface implementation.
 *   4. Delegate the full payment operation to the strategy.
 *   5. Finalize or release idempotency state based on transaction outcome.
 *   6. Dispatch asynchronous domain events via RabbitMQ (PaymentReceivedEvent, OrderFullyPaidEvent).
 *   7. Return the PaymentResult to the caller.
 *
 * Everything that touches the database transactionally (lock acquisition,
 * balance check, Payment creation, Order update) lives in the strategy — NOT here.
 */
final class PaymentService
{
    public function __construct(
        private OrderRepository $orderRepository,
        private UserRepository $userRepository,
        private StrategyRegistry $strategyRegistry,
        private IdempotencyFilterInterface $idempotencyFilter,
        private MessageBusInterface $messageBus,
    ) {}

    /**
     * @throws NotFoundHttpException         if the order or user does not exist
     * @throws \App\Exception\OverpaymentException       if the payment exceeds remaining balance
     * @throws \App\Exception\OrderNotOpenException      if the order is not in Open status
     * @throws \App\Exception\IdempotencyConflictException if the key is a duplicate
     */
    public function pay(int $orderId, ProcessPaymentRequest $request): PaymentResult
    {
        // 1. Fast Redis pre-filter (Stripe pattern): rejects duplicate requests before hitting PostgreSQL
        $this->idempotencyFilter->acquire($request->idempotencyKey);

        try {
            $order = $this->orderRepository->find($orderId);

            if ($order === null) {
                throw new NotFoundHttpException(sprintf('Order #%d not found.', $orderId));
            }

            $user = $this->userRepository->find($request->userId);

            if ($user === null) {
                throw new NotFoundHttpException(sprintf('User #%d not found.', $request->userId));
            }

            $strategy = $this->strategyRegistry->get($request->strategy);

            $result = $strategy->processPayment(
                $order,
                $user,
                $request->amountCents,
                PaymentMethod::from($request->paymentMethod),
                $request->idempotencyKey,
            );

            $payment = $result->payment;

            // 2. Mark idempotency key as committed in Redis with retention TTL (24h)
            $this->idempotencyFilter->commit($request->idempotencyKey, $payment->getId());

            // 3. Dispatch asynchronous domain events to RabbitMQ
            $this->messageBus->dispatch(new PaymentReceivedEvent(
                paymentId:      $payment->getId() ?? 0,
                orderId:        $order->getId() ?? 0,
                userId:         $user->getId() ?? 0,
                amountCents:    $payment->getAmountCents(),
                paymentMethod:  $payment->getPaymentMethod()->value,
                idempotencyKey: $payment->getIdempotencyKey(),
                occurredAt:     $payment->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ));

            if ($order->isFullyPaid()) {
                $this->messageBus->dispatch(new OrderFullyPaidEvent(
                    orderId:        $order->getId() ?? 0,
                    userId:         $user->getId() ?? 0,
                    totalPaidCents: $order->getPaidPriceCents(),
                    occurredAt:     (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                ));
            }

            return $result;
        } catch (IdempotencyConflictException $e) {
            // Keep finalized state in Redis if database uniquely detected it
            $this->idempotencyFilter->commit($request->idempotencyKey);
            throw $e;
        } catch (\Throwable $e) {
            // Release key so client can retry with corrected payload upon business/transient errors
            $this->idempotencyFilter->release($request->idempotencyKey);
            throw $e;
        }
    }
}
