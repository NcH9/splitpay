<?php

declare(strict_types=1);

namespace App\Benchmark;

use Doctrine\DBAL\Connection;

final class ConsistencyAuditor
{
    public function __construct(private readonly Connection $connection) {}

    public function audit(int $orderId): AuditResult
    {
        $order = $this->connection->fetchAssociative(
            'SELECT full_price_cents, paid_price_cents FROM orders WHERE id = :orderId',
            ['orderId' => $orderId],
        );

        if ($order === false) {
            throw new \InvalidArgumentException(sprintf('Order #%d does not exist.', $orderId));
        }

        $payments = $this->connection->fetchAssociative(
            'SELECT COALESCE(SUM(amount_cents), 0) AS total_cents, COUNT(id) AS payment_count FROM payments WHERE order_id = :orderId',
            ['orderId' => $orderId],
        );

        $fullPriceCents = (int) $order['full_price_cents'];
        $paidPriceCents = (int) $order['paid_price_cents'];
        $actualPaymentSumCents = (int) ($payments['total_cents'] ?? 0);

        return new AuditResult(
            orderId: $orderId,
            orderFullPriceCents: $fullPriceCents,
            orderPaidPriceCents: $paidPriceCents,
            actualPaymentSumCents: $actualPaymentSumCents,
            paymentCount: (int) ($payments['payment_count'] ?? 0),
            isConsistent: $paidPriceCents === $actualPaymentSumCents,
            isOverpaid: $actualPaymentSumCents > $fullPriceCents,
            discrepancyCents: abs($paidPriceCents - $actualPaymentSumCents),
        );
    }
}
