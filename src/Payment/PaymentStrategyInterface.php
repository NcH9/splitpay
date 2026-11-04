<?php

declare(strict_types=1);

namespace App\Payment;

use App\Entity\Order;
use App\Entity\User;
use App\Enum\PaymentMethod;
use App\Exception\IdempotencyConflictException;
use App\Exception\OrderNotOpenException;
use App\Exception\OverpaymentException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Common contract for all five payment synchronization strategies.
 *
 * Every strategy must enforce the same set of business invariants:
 *   I1 — no overpayment (sum of payments ≤ order.full_price_cents)
 *   I2 — paid_price_cents stays consistent with sum of Payment records
 *   I3 — payment amount ≤ remaining balance at time of execution
 *   I4 — no lost update under concurrent writes
 *   I5 — idempotency_key is globally unique per payment
 *
 * The synchronization mechanism (lock, version check, atomic SQL, etc.)
 * is the ONLY thing that differs between implementations.
 *
 * #[AutoconfigureTag] on the interface causes every implementing service
 * to be automatically tagged 'app.payment_strategy', collected by StrategyRegistry.
 */
#[AutoconfigureTag('app.payment_strategy')]
interface PaymentStrategyInterface
{
    /**
     * Execute the full payment operation within a single database transaction.
     *
     * @throws OverpaymentException       if the amount would exceed the remaining balance
     * @throws OrderNotOpenException      if the order is not in Open status
     * @throws IdempotencyConflictException if the idempotency_key already exists
     */
    public function processPayment(
        Order $order,
        User $user,
        int $amountCents,
        PaymentMethod $paymentMethod,
        string $idempotencyKey,
    ): PaymentResult;

    /**
     * Machine-readable strategy identifier used for routing and benchmark labelling.
     * Must be one of: baseline | pessimistic | optimistic | mutex | atomic
     */
    public function getName(): string;
}
