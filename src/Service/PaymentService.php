<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\Payment\ProcessPaymentRequest;
use App\Enum\PaymentMethod;
use App\Payment\PaymentResult;
use App\Payment\StrategyRegistry;
use App\Repository\OrderRepository;
use App\Repository\UserRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Thin orchestrator for the payment flow.
 *
 * Responsibilities:
 *   1. Load the Order and User entities (throws HTTP 404 if either is missing).
 *   2. Resolve the requested PaymentStrategyInterface implementation.
 *   3. Delegate the full payment operation to the strategy.
 *   4. Return the PaymentResult to the caller.
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
    ) {}

    /**
     * @throws NotFoundHttpException         if the order or user does not exist
     * @throws \App\Exception\OverpaymentException       if the payment exceeds remaining balance
     * @throws \App\Exception\OrderNotOpenException      if the order is not in Open status
     * @throws \App\Exception\IdempotencyConflictException if the key is a duplicate
     */
    public function pay(int $orderId, ProcessPaymentRequest $request): PaymentResult
    {
        $order = $this->orderRepository->find($orderId);

        if ($order === null) {
            throw new NotFoundHttpException(sprintf('Order #%d not found.', $orderId));
        }

        $user = $this->userRepository->find($request->userId);

        if ($user === null) {
            throw new NotFoundHttpException(sprintf('User #%d not found.', $request->userId));
        }

        $strategy = $this->strategyRegistry->get($request->strategy);

        return $strategy->processPayment(
            $order,
            $user,
            $request->amountCents,
            PaymentMethod::from($request->paymentMethod),
            $request->idempotencyKey,
        );
    }
}
